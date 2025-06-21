<?php
// 启用错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// 检查用户是否登录的函数
function checkLogin() {
    if (!isset($_SESSION['username'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error', 
            'message' => '尚未登录，请先登录',
            'code' => 'NOT_LOGGED_IN'
        ]);
        exit;
    }
    return $_SESSION['username'];
}

// 包含PHPMailer命名空间
require 'PHPMailer-master/src/Exception.php'; 
require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 数据库连接信息
$servername = "localhost";
$username = "db_username";
$password = "db_passwd";
$dbname = "db_name";

// 创建数据库连接
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("连接失败: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

// 获取用户IP地址
function getUserIP() {
    $ip = '';
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

// 获取IP地理位置
function getIPLocation($ip) {
    try {
        $apiKey = 'YOUR_API_KEY'; // 替换为你的IP2Location API密钥
        $url = "https://api.ip2location.io/?key=$apiKey&ip=" . $ip;
        $response = @file_get_contents($url);
        
        if ($response === false) {
            throw new Exception("无法获取地理位置数据");
        }
        
        return json_decode($response, true) ?: [
            'country_name' => '',
            'region_name' => '',
            'city_name' => '',
            'latitude' => 0,
            'longitude' => 0,
            'is_proxy' => 0
        ];
    } catch (Exception $e) {
        error_log("IP地理位置获取失败: " . $e->getMessage());
        return [
            'country_name' => '',
            'region_name' => '',
            'city_name' => '',
            'latitude' => 0,
            'longitude' => 0,
            'is_proxy' => 0
        ];
    }
}

// 验证登录状态，返回用户名或退出
$user = checkLogin();

// 处理POST请求
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    header('Content-Type: application/json');
    
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 验证必要的数据
        if (!isset($data["content"]) || !isset($data["action"])) {
            throw new Exception("缺少必要的数据");
        }

        // 从URL中获取project_id
        $project_id = '';
        if (isset($_GET['project_id'])) {
            $project_id = $_GET['project_id'];
        }
        
        // 如果URL中没有，则从POST数据中获取
        if (empty($project_id) && isset($data['project_id'])) {
            $project_id = $data['project_id'];
        }
        
        // 如果还是空的，则从 Referer 中获取
        if (empty($project_id) && isset($_SERVER['HTTP_REFERER'])) {
            if (preg_match('/project_id=([^&]+)/', $_SERVER['HTTP_REFERER'], $matches)) {
                $project_id = urldecode($matches[1]);
            }
        }

        if (empty($project_id)) {
            throw new Exception("无法获取项目ID");
        }
        
        // 获取作者信息
        $table = (strpos($project_id, 'AMB-CSD-') === 0) ? 'archivesB_room' : 'archives_room';
        $author_stmt = $conn->prepare("SELECT username FROM $table WHERE project_id = ?");
        $author_stmt->bind_param("s", $project_id);
        $author_stmt->execute();
        $author_result = $author_stmt->get_result();
        $author = ($row = $author_result->fetch_assoc()) ? $row['username'] : '';
        $author_stmt->close();

        // 如果当前用户是作者，则直接返回success状态，不执行任何操作
        if ($user === $author) {
            echo json_encode(['status' => 'success']);
            exit;
        }

        $content = $data["content"];
        $action = $data["action"];
        $time = date("Y-m-d H:i:s");

        // 获取IP和地理位置信息
        $ip_addr = getUserIP();
        $locationData = getIPLocation($ip_addr);
        
        // 插入日志记录
        $stmt = $conn->prepare("INSERT INTO copy_logs (Username, project_id, author, ip_addr, country_name, 
                               region_name, city_name, latitude, longitude, is_proxy, Text, Action, Time) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (!$stmt) {
            throw new Exception("预处理语句失败: " . $conn->error);
        }

        $stmt->bind_param("sssssssddisss", 
            $user,
            $project_id,
            $author,
            $ip_addr,
            $locationData['country_name'],
            $locationData['region_name'],
            $locationData['city_name'],
            $locationData['latitude'],
            $locationData['longitude'],
            $locationData['is_proxy'],
            $content,
            $action,
            $time
        );

        if (!$stmt->execute()) {
            throw new Exception("执行失败: " . $stmt->error);
        }

        // 发送邮件通知
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.example.com'; // 替换为实际的SMTP服务器
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@example.com'; // 替换为实际的SMTP用户名
        $mail->Password = 'YOUR_SMTP_PASSWD'; // 替换为实际的SMTP密码
        $mail->SMTPSecure = 'ssl'; // 使用ssl加密
        $mail->Port = 465;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('noreply@example.com', '网站内容管理系统');
        $mail->addAddress('test@example.com'); // 替换为实际的接收邮箱地址
        $mail->addAddress('test@example.com'); // 替换为实际的接收邮箱地址
        $mail->isHTML(true);
        $mail->Subject = '内容被复制';
        
        $mail->Body = "
<!DOCTYPE html>
<html lang='zh-CN'>
<head>
    <meta charset='UTF-8'>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 80%;
            margin: 20px auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            color: #e74c3c;
            text-align: center;
        }
        p {
            line-height: 1.6;
        }
        .content {
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            color: #999;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>内容被复制</h1>
        <div class='content'>
          <p><strong>用户名:</strong> $user</p>
          <p><strong>IP地址:</strong> $ip_addr</p>
          <p><strong>大致位置:</strong>{$locationData['country_name']} {$locationData['region_name']} {$locationData['city_name']}</p>
          <p><strong>经度:</strong>{$locationData['latitude']}</p>
          <p><strong>纬度:</strong>{$locationData['longitude']}</p>
          <p><strong>是否为代理:</strong>{$locationData['is_proxy']}</p>
          <p><strong>内容:</strong> ${content}</p>
          <p><strong>档案ID:</strong> $project_id</p>
          <p><strong>被复制档案作者:</strong> $author</p>
          <p><strong>行为:</strong> ${action}</p>
          <p><strong>时间:</strong> ${time}</p>
</div>
        <div class='footer'>
            <p>本邮件由内容管理系统系统自动发出，请勿回复。</p>
        </div>
    </div>
</body>
</html>
";

        $mail->send();
        echo json_encode(['status' => 'success', 'message' => '']);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

    // 关闭数据库连接
    if (isset($stmt)) {
        $stmt->close();
    }
    $conn->close();
} else {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => '无效的请求方法']);
}
?>