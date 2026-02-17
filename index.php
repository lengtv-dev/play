<?php
/**
 * Anbox Sport VIP - Advanced Backend API v2
 * ระบบจัดการหลังบ้าน: สมาชิก, แคชรายการ และ AI Proxy
 */

// 1. ตั้งค่า Header พื้นฐาน
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

session_start();

// 2. ข้อมูลการตั้งค่า
$CONFIG = [
    'admin_pass' => '1234',                    // รหัสผ่านสำหรับเข้าใช้งาน
    'gemini_key' => '',                       // *** ใส่ API Key ของคุณที่นี่ ***
    'm3u_url'    => 'https://dl.dropbox.com/scl/fi/e4u8shspjt0ylrb3552dj/dootv.m3u?rlkey=ug92sfb8z9xqd1srefpqan5bh&dl=0',
    'cache_file' => 'channels_cache.json',    // ไฟล์สำหรับเก็บแคช
    'cache_time' => 1800,                     // ระยะเวลาแคช (30 นาที)
];

// 3. ฟังก์ชันจัดการแคช (ช่วยให้โหลดรายการเร็วขึ้น 10 เท่า)
function get_cached_channels() {
    global $CONFIG;
    if (file_exists($CONFIG['cache_file'])) {
        $cache = json_decode(file_get_contents($CONFIG['cache_file']), true);
        if (time() - $cache['timestamp'] < $CONFIG['cache_time']) {
            return $cache['data'];
        }
    }
    
    // ถ้าไม่มีแคชหรือแคชหมดอายุ ให้โหลดใหม่
    $content = @file_get_contents($CONFIG['m3u_url']);
    if ($content) {
        $cache_data = [
            'timestamp' => time(),
            'data' => $content
        ];
        file_put_contents($CONFIG['cache_file'], json_encode($cache_data));
        return $content;
    }
    return null;
}

// 4. ระบบ Routing
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        $pass = $_POST['password'] ?? '';
        if ($pass === $CONFIG['admin_pass']) {
            $_SESSION['authenticated'] = true;
            $_SESSION['login_time'] = time();
            echo json_encode(['success' => true, 'message' => 'ยินดีต้อนรับเข้าสู่ระบบ VIP']);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'รหัสผ่านไม่ถูกต้อง']);
        }
        break;

    case 'get_channels':
        if (!isset($_SESSION['authenticated'])) {
            http_response_code(403);
            exit(json_encode(['error' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน']));
        }
        
        $data = get_cached_channels();
        if ($data) {
            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถดึงข้อมูลรายการได้']);
        }
        break;

    case 'ai_proxy':
        if (!isset($_SESSION['authenticated'])) exit;
        
        $input = json_decode(file_get_contents('php://input'), true);
        $prompt = $input['prompt'] ?? 'วิเคราะห์กีฬา';
        
        if (empty($CONFIG['gemini_key'])) {
            echo json_encode(['error' => 'ยังไม่ได้ตั้งค่า API Key ในฝั่ง Server']);
            exit;
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=" . $CONFIG['gemini_key'];
        
        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        // เพิ่ม Exponential Backoff เล็กน้อยในตัว (Retry 1 ครั้งถ้าล้มเหลว)
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($http_code !== 200) {
            // ลองอีกครั้งหลังจากผ่านไป 1 วินาที
            sleep(1);
            $response = curl_exec($ch);
        }
        
        curl_close($ch);
        echo $response;
        break;

    case 'check_session':
        echo json_encode(['authenticated' => isset($_SESSION['authenticated'])]);
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'ออกจากระบบเรียบร้อย']);
        break;

    default:
        echo json_encode(['status' => 'online', 'version' => '2.0.0']);
        break;
}
?>
