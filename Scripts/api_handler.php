<?php
header('Content-Type: application/json');

// --- 環境切り替え設定 ---
define('SELECTED_ENVIRONMENT', 'test'); // 'test' または 'production' に変更

// --- 本番環境用 設定箇所 ---
define('USER_CONFIG_PROD_CLIENT_ID', getenv('JAPANPOST_CLIENT_ID'));
define('USER_CONFIG_PROD_SECRET_KEY', getenv('JAPANPOST_CLIENT_SECRET'));
define('PROD_API_BASE_URL', 'https://api.da.pf.japanpost.jp');

// --- テスト環境用設定 (ここは通常変更不要) ---
define('TEST_API_BASE_URL', 'https://stub-qz73x.da.pf.japanpost.jp');
define('TEST_CLIENT_ID', getenv('JAPANPOST_CLIENT_ID_TEST'));
define('TEST_SECRET_KEY', getenv('JAPANPOST_CLIENT_SECRET_TEST'));

// --- 環境に応じた設定を実際にスクリプトが使用する定数として定義 ---
if (SELECTED_ENVIRONMENT === 'production') {
    define('API_BASE_URL', PROD_API_BASE_URL);
    define('CLIENT_ID', USER_CONFIG_PROD_CLIENT_ID);
    define('SECRET_KEY', USER_CONFIG_PROD_SECRET_KEY);
} elseif (SELECTED_ENVIRONMENT === 'test') {
    define('API_BASE_URL', TEST_API_BASE_URL);
    define('CLIENT_ID', TEST_CLIENT_ID);
    define('SECRET_KEY', TEST_SECRET_KEY);
} else {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'サーバー設定エラー: SELECTED_ENVIRONMENT の値が不正です。']);
    exit;
}

// APIエンドポイント用定数
define('TOKEN_ENDPOINT', API_BASE_URL . '/api/v1/j/token');
define('SEARCH_CODE_ENDPOINT_BASE', API_BASE_URL . '/api/v1/searchcode/');

// タイムアウト設定 (秒)
define('CONNECT_TIMEOUT', 10);
define('REQUEST_TIMEOUT', 30);

/**
 * APIアクセストークンを取得する関数
 * @return string|array アクセストークン文字列、またはエラー情報配列
 */
function get_access_token() {
    $payload = json_encode([
        'grant_type' => 'client_credentials',
        'client_id' => CLIENT_ID,
        'secret_key' => SECRET_KEY
    ]);

    $ch = curl_init(TOKEN_ENDPOINT);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (compatible; PHP cURL)',
        'Content-Length: ' . strlen($payload)
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, CONNECT_TIMEOUT);
    curl_setopt($ch, CURLOPT_TIMEOUT, REQUEST_TIMEOUT);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error_no = curl_errno($ch);
    $curl_error_msg = curl_error($ch);
    curl_close($ch);

    if ($curl_error_no) {
        return ['error' => true, 'message' => "トークン取得cURLエラー: {$curl_error_msg} (Code: {$curl_error_no})", 'status_code' => 503];
    }

    $data = json_decode($response, true);

    if ($http_code !== 200 || !isset($data['token'])) {
        $api_error_code = $data['error_code'] ?? 'N/A';
        $api_message = $data['message'] ?? 'トークンの取得に失敗しました。レスポンスコード: ' . $http_code;
        if (is_array($data) && isset($data['message'])) {
             $api_message .= " APIメッセージ: " . $data['message'];
        } elseif (is_string($response) && !empty($response)) {
             $api_message .= " 生レスポンス: " . substr($response, 0, 2000);
        }
        return ['error' => true, 'message' => "トークンAPIエラー: {$api_message}", 'status_code' => $http_code, 'api_error_code' => $api_error_code];
    }

    return $data['token'];
}
function search_address_by_code($search_code, $token) {
    $search_url = SEARCH_CODE_ENDPOINT_BASE . $search_code;

    $ch = curl_init($search_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Mozilla/5.0 (compatible; PHP cURL)',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, CONNECT_TIMEOUT);
    curl_setopt($ch, CURLOPT_TIMEOUT, REQUEST_TIMEOUT);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error_no = curl_errno($ch);
    $curl_error_msg = curl_error($ch);
    curl_close($ch);

    if ($curl_error_no) {
        return ['error' => true, 'message' => "住所検索cURLエラー: {$curl_error_msg} (Code: {$curl_error_no})", 'status_code' => 503];
    }

    $data = json_decode($response, true);

    if ($http_code !== 200) {
        $api_error_code = $data['error_code'] ?? 'N/A';
        $api_message = $data['message'] ?? '住所情報の取得に失敗しました。';
        return ['error' => true, 'message' => "住所検索APIエラー: {$api_message}", 'status_code' => $http_code, 'api_error_code' => $api_error_code, 'raw_response' => $data];
    }
    if (isset($data['error_code']) && !empty($data['error_code'])) { // error_code が存在し、空でない場合のみエラーとして扱う
         return ['error' => true, 'message' => $data['message'] ?? 'APIからエラーが返されました。', 'status_code' => $http_code, 'api_error_code' => $data['error_code'], 'raw_response' => $data];
    }
    return $data;
}


// メイン処理
// リクエストメソッドがPOSTであるかを確認
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => true, 'message' => 'このエンドポイントはPOSTリクエストのみ受け付けます。']);
    exit;
}

// POSTされた search_code を受け取る
if (!isset($_POST['search_code']) || empty(trim($_POST['search_code']))) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => true, 'message' => '検索コード (search_code) がPOSTされていません。']);
    exit;
}

$search_code_input = trim($_POST['search_code']);
// 入力された検索コードからハイフンを除去
$search_code_input = str_replace('-', '', $search_code_input);

// 1. トークン取得
$token_result = get_access_token(); 

if (is_array($token_result) && isset($token_result['error'])) {
    http_response_code($token_result['status_code'] ?? 500);
    echo json_encode($token_result);
    exit;
}
$access_token = $token_result;

// 2. 取得したトークンとPOSTされたsearch_codeを使って住所情報を検索
$address_data = search_address_by_code($search_code_input, $access_token);

if (isset($address_data['error'])) {
    http_response_code($address_data['status_code'] ?? 500);
} else {
    http_response_code(200);
}

echo json_encode($address_data);

?>
