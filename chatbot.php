<?php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$studentId = (int)$_SESSION['student_db_id'];

// Read input
$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$history = $input['history'] ?? [];

if (empty($message)) {
    echo json_encode(['error' => 'Empty message']);
    exit;
}

// 1. Fetch Student Context
// Find latest assessment
$assStmt = $mysqli->prepare("SELECT id FROM student_assessments WHERE student_id = ? ORDER BY completed_at DESC LIMIT 1");
$assStmt->bind_param('i', $studentId);
$assStmt->execute();
$assRes = $assStmt->get_result();
$assessmentId = null;
if ($row = $assRes->fetch_assoc()) {
    $assessmentId = $row['id'];
}
$assStmt->close();

$studentContext = "";
if ($assessmentId) {
    // Get Top 5 Recommendations
    $recStmt = $mysqli->prepare("
        SELECT r.rank, c.id as course_id, c.course_name, r.match_percentage, r.explanation 
        FROM recommendations r
        JOIN courses c ON r.course_id = c.id
        WHERE r.assessment_id = ?
        ORDER BY r.rank ASC LIMIT 5
    ");
    $recStmt->bind_param('i', $assessmentId);
    $recStmt->execute();
    $recRes = $recStmt->get_result();
    
    $studentContext .= "Student's Top 5 Recommended Courses:\n";
    $topCourseIds = [];
    while ($row = $recRes->fetch_assoc()) {
        $studentContext .= "Rank {$row['rank']}: {$row['course_name']} (Match: {$row['match_percentage']}%)\n";
        $studentContext .= "Explanation: {$row['explanation']}\n\n";
        $topCourseIds[] = [
            'id' => $row['course_id'],
            'course_name' => $row['course_name']
        ];
    }
    $recStmt->close();
} else {
    $studentContext = "Student has not completed an assessment yet.";
}

// 2. Keyword Extraction & Targeted Query
// Fetch all courses and schools to check if mentioned
$dbDataText = "";
$msgLower = strtolower($message);

$matchedCourses = [];
// Auto-include the student's top 5 recommended courses so the AI always has their school lists ready
if (!empty($topCourseIds)) {
    require_once __DIR__ . '/../includes/recommendation_scoring.php';
    
    $coursesToProcess = array_map(function($tc) {
        $tc['course_id'] = $tc['id'];
        return $tc;
    }, $topCourseIds);
    
    $coursesWithSchools = getSchoolRecommendationsForCourses($mysqli, $coursesToProcess, null, $assessmentId);
    
    $dbDataText .= "Database Information Retrieved for this question:\n\n";
    foreach ($coursesWithSchools as $cws) {
        $dbDataText .= "Course: {$cws['course_name']}\n";
        
        if (empty($cws['schools'])) {
            $dbDataText .= "Schools offering this course: None listed in your recommended region.\n\n";
        } else {
            $schoolNames = array_map(function($s) { return $s['name']; }, $cws['schools']);
            $dbDataText .= "Schools offering this course: " . implode(", ", $schoolNames) . "\n\n";
        }
    }
} else {
    $dbDataText = "No specific database records retrieved for this query.";
}

// 3. Construct the prompt with rules
$systemInstruction = "You are a personalized AI Career Guidance Assistant for a system called CareerPath. You are helping a student. Your goal is to answer their questions accurately and concisely.

BEHAVIORAL RULES:
- Career-related questions: Answer fully.
- Education-related questions: Answer fully.
- Follow-up questions: Answer fully, as long as they relate to the conversation.
- General conversation (e.g., small talk, greetings): Answer briefly, then quickly redirect the user toward CareerPath topics.
- Completely unrelated questions (e.g., math homework, coding, politics, recipes): Politely redirect the user, refusing to answer the unrelated topic.
- CareerPath assessment scores, results, and recommendations: You MUST use ONLY the actual data from the system provided in the context below. NEVER invent, alter, recalculate, or guess them.

SCORING ALGORITHM RULES:
- The system calculates a final course match score using a direct 1-stage formula: (Skills Match × 0.40) + (Career Interest × 0.30) + (Personality Fit × 0.20) + (SHS Strand Relevance × 0.10).
- When a student asks how their score was calculated, explain these exact four factors and their specific weights (40%, 30%, 20%, 10%).

FORMATTING RULES (STRICT):
- Format your response like a natural chat message, NOT a markdown document.
- Use short paragraphs and natural conversational sentences.
- DO NOT generate markdown tables, large ### headings, long numbered sections, or report-style layouts.
- You may use **bold text** occasionally for important terms, but avoid excessive bolding.
- You may use simple bullet points only when they improve readability for short lists.
- For course and school availability, if the required database information was not retrieved in the context below, you MUST say that the information could not be verified in the CareerPath database, rather than assuming it does not exist.
- Encourage students to consult their school counselor or teacher when making important education or career decisions. NEVER present yourself as a replacement for the counselor.
- Keep responses appropriate for Senior High School students. Be supportive, clear, and concise.

--- STUDENT CONTEXT ---
$studentContext

--- DATABASE CONTEXT ---
$dbDataText
";

// 4. Groq Integration
$messages = [];
$messages[] = ["role" => "system", "content" => $systemInstruction];

// Append history (limit to last 4 messages to save tokens)
$recentHistory = array_slice($history, -4);
foreach ($recentHistory as $msg) {
    if ($msg['role'] === 'user') {
        $messages[] = ["role" => "user", "content" => $msg['content']];
    } else {
        $messages[] = ["role" => "assistant", "content" => $msg['content']];
    }
}
// Append the new message
$messages[] = ["role" => "user", "content" => $message];

$apiKey = getenv('GROQ_API_KEY') ?: '';

if (empty($apiKey)) {
    echo json_encode(['error' => 'The AI assistant is temporarily unavailable due to missing configuration.']);
    exit;
}

$data = [
    "model" => "openai/gpt-oss-20b",
    "messages" => $messages,
    "temperature" => 0.7,
    "max_tokens" => 800
];

$url = "https://api.groq.com/openai/v1/chat/completions";
$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\nAuthorization: Bearer " . $apiKey . "\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
        'timeout' => 15,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$result = @file_get_contents($url, false, $context);

if ($result === false) {
    echo json_encode(['error' => 'The AI assistant is temporarily unavailable. Please try again later.']);
    exit;
}

$response = json_decode($result, true);
if (isset($response['error'])) {
    $errCode = $response['error']['code'] ?? 'Unknown';
    if ($errCode === 'rate_limit_exceeded' || $errCode === 429 || $errCode === 503) {
        echo json_encode(['error' => 'The AI assistant is temporarily busy or rate-limited. Please try again later.']);
    } else {
        echo json_encode(['error' => 'The AI assistant encountered an error. Please try again later.']);
    }
    exit;
}

$reply = $response['choices'][0]['message']['content'] ?? 'Sorry, I could not generate a response.';
echo json_encode(['reply' => trim($reply)]);
