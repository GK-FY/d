<?php
// index.php - User panel (Daraja STK Push) � STK only sent after popup confirm (AJAX)
session_start();
date_default_timezone_set('Africa/Nairobi');

// Files
$PKG_FILE = __DIR__ . '/packages.json';
$ORD_FILE = __DIR__ . '/orders.json';
$CFG_FILE = __DIR__ . '/config.json';
$MPESA_LOG = __DIR__ . '/mpesa_logs.json';
$SMS_LOG = __DIR__ . '/sms_logs.json';
$DAILY_FILE = __DIR__ . '/daily_purchases.json';
$PENDING_FILE = __DIR__ . '/pending_payments.json';

// JSON helpers
function load_json($f, $default = []) { if (!file_exists($f)) return $default; $s = @file_get_contents($f); $j = @json_decode($s, true); return $j === null ? $default : $j; }
function save_json($f, $d) { return @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT)); }

// Atomic update: holds lock for entire read-modify-write cycle
function atomic_update_json($file, $callback, $default = []) {
    if (!file_exists($file)) touch($file);
    $fp = fopen($file, "c+");
    if (!$fp) return false;
    
    if (flock($fp, LOCK_EX)) {
        // Read current data
        $contents = stream_get_contents($fp);
        $data = $contents ? @json_decode($contents, true) : null;
        if ($data === null) $data = $default;
        
        // Apply modification
        $new_data = $callback($data);
        
        // Write back atomically
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($new_data, JSON_PRETTY_PRINT));
        fflush($fp);
        
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    
    fclose($fp);
    return false;
}

function load_json_locked($file, $default = []) {
    if (!file_exists($file)) return $default;
    $fp = fopen($file, "r");
    if (!$fp) return $default;
    
    if (flock($fp, LOCK_SH)) {
        $contents = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = @json_decode($contents, true);
        return $data === null ? $default : $data;
    }
    
    fclose($fp);
    return $default;
}

function save_json_locked($file, $data) {
    if (!file_exists($file)) touch($file);
    $fp = fopen($file, "c+");
    if (!$fp) return false;
    
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    
    fclose($fp);
    return false;
}







$packages = load_json($PKG_FILE, []);
$orders   = load_json_locked($ORD_FILE, []);
$config   = load_json($CFG_FILE, []);

// Defaults (admin should set real values in admin panel)
$config += [
  'logo_text' => "FY'S PROPERTY",
  'background_url' => 'https://iili.io/25NgBx1.jpg',
  'daraja_env' => 'sandbox', // sandbox | live
  'daraja_consumer_key' => '',
  'daraja_consumer_secret' => '',
  'daraja_shortcode' => '',
  'daraja_passkey' => '',
  'daraja_callback' => '',
  'default_transaction_type' => 'CustomerPayBillOnline',
  'default_account_reference' => '',
  'paybill_number' => '',
  'till_number' => '',
  'sms_apikey' => '',
  'sms_partnerID' => '',
  'sms_shortcode' => 'TextSMS',
  'admin_sms_number' => '0700363422',
  'cron_key' => ''
];

// helpers
function genOrderNo(){ return "FY'S-" . rand(100000, 999999); }
function mpesa_log($txt){ global $MPESA_LOG; @file_put_contents($MPESA_LOG, date('c').' '.$txt.PHP_EOL, FILE_APPEND); }
function sms_log($txt){ global $SMS_LOG; @file_put_contents($SMS_LOG, date('c').' '.$txt.PHP_EOL, FILE_APPEND); }
function setSwal($arr){ $_SESSION['swal'] = $arr; }

function addPendingPayment($checkout, $order_no, $pending_file) {
    atomic_update_json($pending_file, function($pending) use ($checkout, $order_no) {
        $pending[] = ['checkout' => $checkout, 'order_no' => $order_no, 'created' => time()];
        return $pending;
    }, []);
}

// Record daily purchase (only called when payment is successful)
function recordDailyPurchase($phone, $package_name, $daily_file) {
    $phone_clean = preg_replace('/[^0-9]/', '', $phone);
    $today = date('Y-m-d');
    
    atomic_update_json($daily_file, function($daily) use ($phone_clean, $package_name, $today) {
        // Cleanup: Remove entries older than today to keep file size manageable
        $daily = array_filter($daily, function($entry) use ($today) {
            return isset($entry['date']) && $entry['date'] === $today;
        });
        
        // Check if already recorded (prevent duplicates)
        foreach ($daily as $entry) {
            if (isset($entry['phone']) && $entry['phone'] === $phone_clean && 
                isset($entry['package']) && $entry['package'] === $package_name &&
                isset($entry['date']) && $entry['date'] === $today) {
                return array_values($daily); // Already recorded, return as-is
            }
        }
        
        // Record this purchase
        $daily[] = [
            'phone' => $phone_clean,
            'package' => $package_name,
            'date' => $today,
            'time' => date('H:i:s')
        ];
        return array_values($daily);
    }, []);
}

// Daraja endpoints & helpers (unchanged)
function endpoints_for_env($env) {
    $e = strtolower(trim($env));
    if ($e === 'production' || $e === 'live') {
        return [
            'oauth'  => 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
            'stk'    => 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
            'query'  => 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query'
        ];
    }
    return [
        'oauth'  => 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
        'stk'    => 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
        'query'  => 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query'
    ];
}
function daraja_get_token($consumer_key, $consumer_secret, $env='sandbox'){
    $eps = endpoints_for_env($env);
    $url = $eps['oauth'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $consumer_key.':'.$consumer_secret,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch); $err = curl_error($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    mpesa_log("TOKEN_RESPONSE http={$http} resp=".($resp?:$err));
    $j = @json_decode($resp, true);
    return $j['access_token'] ?? null;
}
function build_stk_password($businessShortCode, $passkey){
    $ts = date('YmdHis');
    $pw = base64_encode($businessShortCode . $passkey . $ts);
    return [$pw, $ts];
}
function parse_query_response($decoded) {
    $out = [
        'ResultCode' => null,
        'ResultDesc' => null,
        'MpesaReceiptNumber' => null,
        'Amount' => null,
        'PhoneNumber' => null,
        'CheckoutRequestID' => null,
        'raw' => $decoded
    ];
    if (!is_array($decoded)) return $out;
    if (isset($decoded['ResultCode'])) $out['ResultCode'] = $decoded['ResultCode'];
    if (isset($decoded['ResultDesc'])) $out['ResultDesc'] = $decoded['ResultDesc'];
    if (isset($decoded['Body']['stkCallback'])) {
        $stk = $decoded['Body']['stkCallback'];
        if (isset($stk['ResultCode'])) $out['ResultCode'] = $stk['ResultCode'];
        if (isset($stk['ResultDesc'])) $out['ResultDesc'] = $stk['ResultDesc'];
        if (isset($stk['CheckoutRequestID'])) $out['CheckoutRequestID'] = $stk['CheckoutRequestID'];
        $items = $stk['CallbackMetadata']['Item'] ?? [];
        foreach ($items as $it) {
            $name = strtolower($it['Name'] ?? '');
            $val  = $it['Value'] ?? null;
            if (strpos($name,'mpesareceipt')!==false || strpos($name,'receipt')!==false) $out['MpesaReceiptNumber'] = $val;
            if (strpos($name,'amount')!==false) $out['Amount'] = $val;
            if (strpos($name,'phonenumber')!==false) $out['PhoneNumber'] = $val;
        }
    }
    if (isset($decoded['Result']['CallbackMetadata']['Item']) && is_array($decoded['Result']['CallbackMetadata']['Item'])) {
        foreach ($decoded['Result']['CallbackMetadata']['Item'] as $it) {
            $n = strtolower($it['Name'] ?? '');
            $v = $it['Value'] ?? null;
            if (strpos($n,'mpesareceipt')!==false || strpos($n,'receipt')!==false) $out['MpesaReceiptNumber'] = $v;
            if (strpos($n,'amount')!==false) $out['Amount'] = $v;
            if (strpos($n,'phonenumber')!==false) $out['PhoneNumber'] = $v;
        }
    }
    if (isset($decoded['CheckoutRequestID'])) $out['CheckoutRequestID'] = $decoded['CheckoutRequestID'];
    if (isset($decoded['MpesaReceiptNumber'])) $out['MpesaReceiptNumber'] = $decoded['MpesaReceiptNumber'];
    if (isset($decoded['Amount'])) $out['Amount'] = $decoded['Amount'];
    if (isset($decoded['PhoneNumber'])) $out['PhoneNumber'] = $decoded['PhoneNumber'];
    return $out;
}
function daraja_query_checkout($config, $checkoutId) {
    $env = $config['daraja_env'] ?? 'sandbox';
    $token = daraja_get_token($config['daraja_consumer_key'] ?? '', $config['daraja_consumer_secret'] ?? '', $env);
    if (!$token) { mpesa_log("QUERY_NO_TOKEN checkout={$checkoutId}"); return ['error'=>'no_token']; }
    $eps = endpoints_for_env($env);
    list($password, $timestamp) = build_stk_password($config['daraja_shortcode'] ?? '', $config['daraja_passkey'] ?? '');
    $payload = [
        "BusinessShortCode" => $config['daraja_shortcode'],
        "Password" => $password,
        "Timestamp" => $timestamp,
        "CheckoutRequestID" => $checkoutId
    ];
    $ch = curl_init($eps['query']);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER    => ["Authorization: Bearer $token", 'Content-Type: application/json'],
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode($payload),
        CURLOPT_RETURNTRANSFER=> true,
        CURLOPT_SSL_VERIFYPEER=> false,
        CURLOPT_CONNECTTIMEOUT=> 10,
        CURLOPT_TIMEOUT=> 20
    ]);
    $resp = curl_exec($ch); $err = curl_error($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    mpesa_log("QUERY_REQUEST checkout={$checkoutId} http={$http} resp=".($resp?:$err));
    $decoded = @json_decode($resp, true);
    if (!$decoded) return ['error'=>'invalid_response','raw'=>$resp];
    $parsed = parse_query_response($decoded);
    return ['ok'=>true,'http'=>$http,'parsed'=>$parsed,'raw'=>$decoded];
}
function update_order_from_parsed($checkoutId, $parsed, &$orders, $config, $ORD_FILE) {
    $foundKey = null;
    foreach ($orders as $k=>$o) {
        if (!empty($o['daraja_checkout']) && $o['daraja_checkout'] === $checkoutId) { $foundKey = $k; break; }
    }
    if (!$foundKey) { mpesa_log("UPDATE_NO_ORDER checkout={$checkoutId}"); return ['error'=>'no_order']; }
    $rc = $parsed['ResultCode'];
    $desc = $parsed['ResultDesc'] ?? '';
    if ($rc === null) { return ['status'=>'no_result']; }
    if ((int)$rc === 0) {
        $receipt = $parsed['MpesaReceiptNumber'] ?? null;
        $amt = $parsed['Amount'] ?? null;
        $phone = $parsed['PhoneNumber'] ?? null;
        $orders[$foundKey]['status'] = 'paid';
        $orders[$foundKey]['paid_at'] = date('Y-m-d H:i:s');
        if ($receipt) $orders[$foundKey]['mpesa_receipt'] = $receipt;
        if ($amt) $orders[$foundKey]['paid_amount'] = $amt;
        if ($phone) $orders[$foundKey]['paid_phone'] = $phone;
        save_json_locked($ORD_FILE, $orders);
        // send admin SMS
        $admin = $config['admin_sms_number'] ?? '0700363422';
        $custNo = $orders[$foundKey]['phone'] ?? '';
        $amtText = number_format((float)($orders[$foundKey]['paid_amount'] ?? $orders[$foundKey]['amount']), 2);
        $txref = $orders[$foundKey]['order_no'];
        $dt = date('n/j/y'); $tm = date('g:i A'); $newbal = number_format((mt_rand(1000,50000))/100, 2);
        $sms_message = "{$txref} Confirmed.You have received Ksh{$amtText} from BINGWA  CUSTOMER {$custNo} on {$dt} at {$tm}  New M-PESA balance is Ksh{$newbal}. Earn interest daily on Ziidi MMF,Dial *334#";
        if (!empty($config['sms_apikey']) && !empty($config['sms_partnerID'])) {
            $payload = json_encode([
                'apikey' => $config['sms_apikey'],
                'partnerID' => $config['sms_partnerID'],
                'message' => $sms_message,
                'shortcode' => $config['sms_shortcode'] ?? 'TextSMS',
                'mobile' => preg_match('/^(07|01)\d{8}$/',$admin) ? '254'.substr(preg_replace('/\D/','',$admin),1) : preg_replace('/\D/','',$admin)
            ]);
            $ch = curl_init('https://sms.textsms.co.ke/api/services/sendsms/');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 20
            ]);
            $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
            sms_log("SENT_TO:{$admin} MSG:{$sms_message} RESP:".($resp?:$err));
        } else {
            sms_log("SMS_SKIPPED missing creds MSG:{$sms_message}");
        }
        mpesa_log("ORDER_UPDATED_PAID order={$foundKey} checkout={$checkoutId}");
        return ['status'=>'paid','order'=>$foundKey,'receipt'=>$receipt];
    } else {
        $orders[$foundKey]['status'] = 'failed';
        $orders[$foundKey]['failed_at'] = date('Y-m-d H:i:s');
        $orders[$foundKey]['failed_desc'] = $desc;
        save_json_locked($ORD_FILE, $orders);
        mpesa_log("ORDER_UPDATED_FAILED order={$foundKey} checkout={$checkoutId} desc={$desc}");
        return ['status'=>'failed','order'=>$foundKey,'desc'=>$desc];
    }
}

// ---------------- Public endpoints ----------------

// Query single checkout (client Confirm action)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'queryStatus') {
    header('Content-Type: application/json; charset=utf-8');
    $checkout = trim($_GET['checkout'] ?? '');
    if ($checkout === '') { echo json_encode(['status'=>'error','message'=>'Missing checkout parameter']); exit; }
    $result = daraja_query_checkout($config, $checkout);
    if (isset($result['error'])) {
        echo json_encode(['status'=>'error','message'=>$result['error'],'raw'=>$result['raw'] ?? null]); exit;
    }
    $parsed = $result['parsed'] ?? null;
    if ($parsed) {
        $orders = load_json_locked($ORD_FILE, []);
        $u = update_order_from_parsed($checkout, $parsed, $orders, $config, $ORD_FILE);
    } else {
        $u = ['status'=>'no_parsed'];
    }
    echo json_encode(['status'=>'ok','http'=>$result['http'] ?? null,'parsed'=>$parsed,'update'=>$u]); exit;
}

// Cron check pending (optional)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'cronCheckPending') {
    header('Content-Type: application/json; charset=utf-8');
    $cron_key = $config['cron_key'] ?? '';
    if ($cron_key !== '') {
        $provided = trim($_GET['key'] ?? '');
        if ($provided !== $cron_key) { echo json_encode(['status'=>'error','message'=>'Invalid cron key']); exit; }
    }
    $orders = load_json_locked($ORD_FILE, []);
    $pending = [];
    foreach ($orders as $k=>$o) {
        if (($o['status'] ?? '') === 'pending' && !empty($o['daraja_checkout'])) $pending[$k] = $o;
    }
    $summary = ['checked'=>0,'updated'=>0,'errors'=>[]];
    foreach ($pending as $k=>$o) {
        $checkout = $o['daraja_checkout'];
        $summary['checked']++;
        $res = daraja_query_checkout($config, $checkout);
        if (isset($res['error'])) { $summary['errors'][] = "checkout={$checkout} err=".$res['error']; continue; }
        $parsed = $res['parsed'] ?? null;
        $orders = load_json_locked($ORD_FILE, []);
        $u = update_order_from_parsed($checkout, $parsed, $orders, $config, $ORD_FILE);
        if ($u['status'] === 'paid' || $u['status'] === 'failed') $summary['updated']++;
    }
    echo json_encode(['status'=>'ok','summary'=>$summary]); exit;
}

// ---------------- POST: create order and start Daraja STK Push ----------------
// Supports AJAX: when client sends 'ajax=1' (POST), server returns JSON instead of redirect.
// Otherwise behaves as before (redirect to ?show=1&order=...&checkout=...).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'init') {
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] == '1';
    $pkgName = trim($_POST['packageName'] ?? '');
    $pkgPrice = trim($_POST['packagePrice'] ?? '');
    $payer = trim($_POST['payerNumber'] ?? '');
    $recv  = trim($_POST['phoneNumber'] ?? '');

    // validate numbers
    if (!preg_match('/^(07|01)\d{8}$/', $payer)) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>'Invalid paying number']); exit; }
        setSwal(['icon'=>'error','title'=>'Invalid Paying Number','text'=>'Paying number must start with 07 or 01 and be 10 digits.']);
        header('Location:index.php'); exit;
    }
    if (!preg_match('/^(07|01)\d{8}$/', $recv)) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>'Invalid receiver number']); exit; }
        setSwal(['icon'=>'error','title'=>'Invalid Receiver Number','text'=>'Receiver number must start with 07 or 01 and be 10 digits.']);
        header('Location:index.php'); exit;
    }

    // Check for daily purchase limit BEFORE creating order (CHECK ONLY, don't record yet)
    $targetPackage = null;
    foreach ($packages as $pkg) {
        if ($pkg['name'] === $pkgName) {
            $targetPackage = $pkg;
            break;
        }
    }
    
    // ONLY CHECK if already purchased today for once_per_day packages
    // Do NOT record yet - that happens only when payment is confirmed
    // Check is based on RECEIVER phone (not payer) for ANY package
    if ($targetPackage && !empty($targetPackage['once_per_day'])) {
        $receiver_clean = preg_replace('/[^0-9]/', '', $recv);
        $today = date('Y-m-d');
        
        // Read daily purchases (read-only check)
        $daily = load_json_locked($DAILY_FILE, []);
        
        // Check if receiver phone already purchased ANY package today
        foreach ($daily as $entry) {
            if (isset($entry['phone']) && $entry['phone'] === $receiver_clean && 
                isset($entry['date']) && $entry['date'] === $today) {
                
                $errMsg = 'This number has already purchased a package today. Please try again tomorrow.';
                if ($isAjax) { 
                    header('Content-Type: application/json'); 
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'daily_limit',
                        'message' => $errMsg
                    ]); 
                    exit; 
                }
                setSwal(['icon'=>'warning','title'=>'Daily Limit Reached','text'=>$errMsg]);
                header('Location:index.php'); 
                exit;
            }
        }
    }

    // create order
    $orders = load_json_locked($ORD_FILE, []);
    $ord = genOrderNo();
    $created_at = date('Y-m-d H:i:s');
    $orders[$ord] = [
        'order_no'=>$ord,
        'package'=>$pkgName,
        'amount'=>$pkgPrice,
        'phone'=>$recv,
        'payer'=>$payer,
        'status'=>'pending',
        'created'=>$created_at
    ];
    save_json_locked($ORD_FILE, $orders);

    // Daraja init
    $env = $config['daraja_env'] ?? 'sandbox';
    $ck = $config['daraja_consumer_key'] ?? '';
    $cs = $config['daraja_consumer_secret'] ?? '';
    $passkey = $config['daraja_passkey'] ?? '';
    $accRef = trim($config['default_account_reference'] ?: $orders[$ord]['order_no']);
    $txType = $config['default_transaction_type'] ?? 'CustomerPayBillOnline';

    if (empty($ck) || empty($cs) || empty($passkey) || empty($config['daraja_shortcode'])) {
        $errMsg = 'Daraja credentials (consumer key/secret/passkey/shortcode) missing. Please ask admin to configure.';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>$errMsg]); exit; }
        setSwal(['icon'=>'error','title'=>'Payment Error','text'=>$errMsg]);
        header('Location:index.php'); exit;
    }

    $token = daraja_get_token($ck, $cs, $env);
    if (!$token) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>'Could not obtain Daraja token']); exit; }
        setSwal(['icon'=>'error','title'=>'Payment Error','text'=>'Could not obtain Daraja token.']);
        header('Location:index.php'); exit;
    }

    // Callback URL - Must point to index.php where the callback handler is
    $callback = trim($config['daraja_callback']);
    if ($callback === '') {
        // Auto-detect the callback URL
        $domain = getenv('REPLIT_DEV_DOMAIN') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $callback = 'https://' . $domain . '/index.php';
    }
    // Ensure callback URL points to index.php (where callback handler is)
    if (strpos($callback, 'index.php') === false) {
        $callback = rtrim($callback, '/') . '/index.php';
    }
    if ($env === 'live' && stripos($callback, 'https://') !== 0) {
        $errMsg = 'Callback URL must be HTTPS for live Daraja. Configure Callback in Admin settings.';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>$errMsg]); exit; }
        setSwal(['icon'=>'error','title'=>'Payment Error','html'=>'Callback URL must be <b>HTTPS</b> for live Daraja. Configure Callback in Admin settings.']);
        header('Location:index.php'); exit;
    }

    // BusinessShortCode (shortcode must be numeric)
    $businessShortCode = trim($config['daraja_shortcode'] ?? '');
    if (empty($businessShortCode) || !preg_match('/^\d+$/', $businessShortCode)) {
        $msg = "Daraja Shortcode (store number) is missing or invalid. Please set 'Daraja Shortcode' in admin settings.";
        mpesa_log("MISSING_SHORTCODE: ".$msg);
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>$msg]); exit; }
        setSwal(['icon'=>'error','title'=>'Payment Error','text'=>$msg]);
        header('Location:index.php'); exit;
    }

    // Determine PartyB (target)
    $partyB = '';
    if ($txType === 'CustomerBuyGoodsOnline') {
        $partyB = trim($config['till_number'] ?: '');
        if ($partyB === '') $partyB = trim($config['paybill_number'] ?: $businessShortCode);
    } else {
        $partyB = trim($config['paybill_number'] ?: '');
        if ($partyB === '') $partyB = trim($config['till_number'] ?: $businessShortCode);
    }
    if ($partyB === '') $partyB = $businessShortCode;

    // build STK payload and call
    list($password, $timestamp) = build_stk_password($businessShortCode, $passkey);
    $eps = endpoints_for_env($env);
    $num = preg_replace('/\D/','', $payer);
    if (preg_match('/^0(7|1)\d{8}$/', $num)) $num = '254'.substr($num,1);
    $body = [
        'BusinessShortCode' => $businessShortCode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => $txType,
        'Amount' => (int)round($orders[$ord]['amount']),
        'PartyA' => $num,
        'PartyB' => $partyB,
        'PhoneNumber' => $num,
        'CallBackURL' => $callback,
        'AccountReference' => substr($accRef,0,12),
        'TransactionDesc' => substr("Payment for {$accRef}",0,100)
    ];

    mpesa_log("STK_REQUEST order={$ord} body=" . json_encode($body));
    $ch = curl_init($eps['stk']);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER    => ["Authorization: Bearer $token", 'Content-Type: application/json'],
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode($body),
        CURLOPT_RETURNTRANSFER=> true,
        CURLOPT_SSL_VERIFYPEER=> false,
        CURLOPT_CONNECTTIMEOUT=> 10,
        CURLOPT_TIMEOUT=> 30
    ]);
    $resp = curl_exec($ch); $err = curl_error($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    mpesa_log("STK_RESPONSE order={$ord} http={$http} resp=" . ($resp?:$err));
    $decoded = @json_decode($resp, true);
    if (!$decoded) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>'Payment initiation failed (no response)']); exit; }
        setSwal(['icon'=>'error','title'=>'Payment Error','text'=>'Payment initiation failed. Please contact the merchant for assistance.']);
        header('Location:index.php'); exit;
    }
    $ok = (isset($decoded['ResponseCode']) && (string)$decoded['ResponseCode'] === '0') || (isset($decoded['responseCode']) && (string)$decoded['responseCode'] === '0');
    if (!$ok) {
        $errMsg = $decoded['errorMessage'] ?? $decoded['error'] ?? $decoded['ResponseDescription'] ?? json_encode($decoded);
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>"Daraja error: {$errMsg}"]); exit; }
        setSwal(['icon'=>'error','title'=>'Payment Error','html'=>"<b>Daraja error</b>: ".htmlspecialchars($errMsg)]);
        header('Location:index.php'); exit;
    }

    // Save merchant & checkout IDs
    $merchantReq = $decoded['MerchantRequestID'] ?? ($decoded['merchantRequestID'] ?? '');
    $checkoutReq = $decoded['CheckoutRequestID'] ?? ($decoded['checkoutRequestID'] ?? '');
    $orders = load_json_locked($ORD_FILE, []);
    $orders[$ord]['daraja_merchant'] = $merchantReq;
    $orders[$ord]['daraja_checkout'] = $checkoutReq;
    $orders[$ord]['daraja_partyB'] = $partyB;
    $orders[$ord]['daraja_shortcode'] = $businessShortCode;
    $orders[$ord]['daraja_initiated_at'] = date('Y-m-d H:i:s');
    save_json_locked($ORD_FILE, $orders);

    // Add to pending payments for background checker
    addPendingPayment($checkoutReq, $ord, $PENDING_FILE);

    // AJAX response (so client can immediately show popup with details)
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'=>'ok',
            'order' => $ord,
            'checkout' => $checkoutReq,
            'merchant' => $merchantReq,
            'created' => $orders[$ord]['created'],
            'package' => $orders[$ord]['package'],
            'amount' => $orders[$ord]['amount'],
            'payer' => $orders[$ord]['payer'],
            'receiver' => $orders[$ord]['phone']
        ]);
        exit;
    }

    // Non-AJAX fallback: redirect to ?show=1 to trigger popup
    $redir = 'index.php?show=1&order='.urlencode($ord).'&checkout='.urlencode($checkoutReq);
    header('Location: ' . $redir);
    exit;
}

// Daraja callback (incoming JSON)
$rawBody = @file_get_contents('php://input');
$incoming = $rawBody ? @json_decode($rawBody, true) : null;
if (!empty($incoming) && isset($incoming['Body']['stkCallback'])) {
    $cb = $incoming['Body']['stkCallback'];
    $merchantReq = $cb['MerchantRequestID'] ?? null;
    $checkoutReq = $cb['CheckoutRequestID'] ?? null;
    $resultCode = isset($cb['ResultCode']) ? intval($cb['ResultCode']) : null;
    $resultDesc = $cb['ResultDesc'] ?? null;

    mpesa_log("CALLBACK RECEIVED MR:{$merchantReq} CR:{$checkoutReq} RC:{$resultCode} RD:".json_encode($cb));

    // Locate order by checkout id
    $orders = load_json_locked($ORD_FILE, []);
    $foundKey = null;
    foreach ($orders as $k=>$o) {
        if (!empty($o['daraja_checkout']) && $o['daraja_checkout'] === $checkoutReq) { $foundKey = $k; break; }
    }

    if ($foundKey) {
        if ($resultCode === 0) {
            // extract metadata
            $items = $cb['CallbackMetadata']['Item'] ?? [];
            $receipt = null; $amount = null; $phone = null;
            foreach ($items as $it) {
                $name = strtolower($it['Name'] ?? '');
                $val = $it['Value'] ?? null;
                if (strpos($name,'mpesareceipt')!==false || strpos($name,'receipt')!==false) $receipt = $val;
                if (strpos($name,'amount')!==false) $amount = $val;
                if (strpos($name,'phonenumber')!==false) $phone = $val;
            }

            $orders[$foundKey]['status'] = 'paid';
            $orders[$foundKey]['paid_at'] = date('Y-m-d H:i:s');
            if ($receipt) $orders[$foundKey]['mpesa_receipt'] = $receipt;
            if ($amount) $orders[$foundKey]['paid_amount'] = $amount;
            if ($phone) $orders[$foundKey]['paid_phone'] = $phone;
            save_json_locked($ORD_FILE, $orders);
            
            // Record daily purchase for once_per_day packages (only when payment successful)
            // Record receiver phone (not payer) to enforce once-per-day limit per receiver
            $package_name = $orders[$foundKey]['package'] ?? '';
            $payer_phone = $orders[$foundKey]['payer'] ?? '';
            $receiver_phone = $orders[$foundKey]['phone'] ?? '';
            foreach ($packages as $pkg) {
                if ($pkg['name'] === $package_name && !empty($pkg['once_per_day'])) {
                    recordDailyPurchase($receiver_phone, $package_name, $DAILY_FILE);
                    mpesa_log("DAILY_PURCHASE_RECORDED receiver={$receiver_phone} package={$package_name}");
                    break;
                }
            }
            
            // Send SMS to customer (receiver) about successful purchase
            if (!empty($receiver_phone) && !empty($config['sms_apikey']) && !empty($config['sms_partnerID'])) {
                $order_no = $orders[$foundKey]['order_no'] ?? '';
                $cust_message = "Payment received! Your {$package_name} order {$order_no} is confirmed. Receipt: {$receipt}. Thank you for your purchase!";
                $cust_payload = json_encode([
                    'apikey' => $config['sms_apikey'],
                    'partnerID' => $config['sms_partnerID'],
                    'message' => $cust_message,
                    'shortcode' => $config['sms_shortcode'] ?? 'TextSMS',
                    'mobile' => preg_match('/^(07|01)\d{8}$/',$receiver_phone) ? '254'.substr(preg_replace('/\D/','',$receiver_phone),1) : preg_replace('/\D/','',$receiver_phone)
                ]);
                $ch2 = curl_init('https://sms.textsms.co.ke/api/services/sendsms/');
                curl_setopt_array($ch2, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => $cust_payload,
                    CURLOPT_TIMEOUT => 20
                ]);
                $cust_resp = curl_exec($ch2); $cust_err = curl_error($ch2); curl_close($ch2);
                sms_log("SENT_TO_CUSTOMER:{$receiver_phone} MSG:{$cust_message} RESP:".($cust_resp?:$cust_err));
            }

            // send admin SMS (same format)
            $admin = $config['admin_sms_number'] ?? '0700363422';
            $custNo = $orders[$foundKey]['phone']; // receiver number should appear in message
            $amt = number_format((float)($orders[$foundKey]['paid_amount'] ?? $orders[$foundKey]['amount']), 2);
            $txref = $orders[$foundKey]['order_no']; // Order number
            $dt = date('n/j/y'); $tm = date('g:i A');
            $newbal = number_format((mt_rand(1000,50000))/100, 2);
            $sms_message = "{$txref} Confirmed.You have received Ksh{$amt} from BINGWA  CUSTOMER {$custNo} on {$dt} at {$tm}  New M-PESA balance is Ksh{$newbal}. Earn interest daily on Ziidi MMF,Dial *334#";
            if (!empty($config['sms_apikey']) && !empty($config['sms_partnerID'])) {
                $payload = json_encode([
                    'apikey' => $config['sms_apikey'],
                    'partnerID' => $config['sms_partnerID'],
                    'message' => $sms_message,
                    'shortcode' => $config['sms_shortcode'] ?? 'TextSMS',
                    'mobile' => preg_match('/^(07|01)\d{8}$/',$admin) ? '254'.substr(preg_replace('/\D/','',$admin),1) : preg_replace('/\D/','',$admin)
                ]);
                $ch = curl_init('https://sms.textsms.co.ke/api/services/sendsms/');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_TIMEOUT => 20
                ]);
                $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
                sms_log("SENT_TO:{$admin} RESP:".($resp?:$err));
            } else {
                sms_log("SMS_SKIPPED missing creds MSG:{$sms_message}");
            }
            mpesa_log("ORDER_MARKED_PAID order={$foundKey} tx={$txref}");
        } else {
            $orders[$foundKey]['status'] = 'failed';
            $orders[$foundKey]['failed_at'] = date('Y-m-d H:i:s');
            $orders[$foundKey]['failed_desc'] = $resultDesc;
            save_json_locked($ORD_FILE, $orders);
            mpesa_log("ORDER_FAILED order={$foundKey} desc={$resultDesc}");
        }
    } else {
        mpesa_log("CALLBACK_NO_ORDER checkout={$checkoutReq}");
    }

    // Daraja expects a JSON response with ResultCode 0
    header('Content-Type: application/json');
    echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']);
    exit;
}

// ---------------- Check status form handler (non-AJAX fallback) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'check') {
    $chk = trim($_POST['check_no'] ?? '');
    $orders = load_json_locked($ORD_FILE, []);
    if ($chk && isset($orders[$chk])) {
        $o = $orders[$chk];
        $html = "<p><b>Package:</b> {$o['package']}</p><p><b>Amount:</b> KSH {$o['amount']}</p><p><b>Status:</b> {$o['status']}</p><p><b>Created:</b> {$o['created']}</p>";
        if (!empty($o['daraja_checkout'])) $html .= "<p><b>CheckoutRequestID:</b> {$o['daraja_checkout']}</p>";
        if (!empty($o['mpesa_receipt'])) $html .= "<p><b>Receipt:</b> {$o['mpesa_receipt']}</p>";
        setSwal(['icon'=>'info','title'=>"Order {$o['order_no']}", 'html'=>$html]);
    } else {
        setSwal(['icon'=>'error','title'=>'Not Found','text'=>"Order {$chk} not found."]);
    }
    header('Location:index.php'); exit;
}

// When page is rendered, check for ?show=1&order=&checkout= and prepare data for client popup
$showOrder = false;
$showOrderData = null;
if (isset($_GET['show']) && $_GET['show'] == '1' && !empty($_GET['order'])) {
    $orders = load_json_locked($ORD_FILE, []);
    $ordKey = $_GET['order'];
    if (isset($orders[$ordKey])) {
        $o = $orders[$ordKey];
        $o['checkout'] = $_GET['checkout'] ?? ($o['daraja_checkout'] ?? '');
        $showOrder = true;
        $showOrderData = [
            'order_no' => $o['order_no'] ?? '',
            'package' => $o['package'] ?? '',
            'receiver' => $o['phone'] ?? '',
            'payer' => $o['payer'] ?? '',
            'created' => $o['created'] ?? '',
            'checkout' => $o['checkout'] ?? ''
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars($config['logo_text']); ?></title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body{margin:0;font-family:Inter,Arial,Helvetica,sans-serif;background:url('<?php echo htmlspecialchars($config['background_url']); ?>') center/cover fixed;color:#111;}
    .wrap{max-width:980px;margin:36px auto;background:rgba(255,255,255,0.96);padding:26px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.08);}
    h1{text-align:center;margin:0 0 18px;font-weight:800;color:#0f172a;}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;}
    .card{background:#fff;border-radius:12px;box-shadow:0 6px 20px rgba(16,24,40,0.06);overflow:hidden;display:flex;flex-direction:column;}
    .card img{width:100%;height:160px;object-fit:cover;border-bottom:1px solid #f0f0f0;}
    .card .body{padding:14px;display:flex;flex-direction:column;gap:8px;}
    .card h2{margin:0;font-size:18px;color:#0f172a;}
    .card p{margin:0;color:#475569;font-size:14px;}
    .price{font-weight:800;font-size:16px;color:#0b6623;}
    .buy{margin-left:auto;padding:10px 12px;border-radius:8px;border:none;background:linear-gradient(90deg,#10b981,#06b6d4);color:white;font-weight:700;cursor:pointer;}
    .buy:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(3,105,161,0.08);}
    .status{margin-top:22px;text-align:center;}
    .status input{padding:12px;border-radius:8px;border:1px solid #e6e6e6;width:320px;max-width:100%;}
    .status button{padding:12px 16px;border-radius:8px;background:#2563eb;color:#fff;border:none;cursor:pointer;margin-left:10px;}
    .muted{font-size:13px;color:#64748b;margin-top:8px;}
    @media(max-width:640px){ .grid{grid-template-columns:1fr;} .status input{width:100%;margin-bottom:8px;} .status button{margin-left:0;width:100%;} }
  </style>
</head>
<body>
  <div class="wrap">
    <h1><?php echo htmlspecialchars($config['logo_text']); ?></h1>

    <div class="grid">
      <?php if (empty($packages)): ?>
        <div style="padding:20px;border-radius:8px;background:#fff;">No packages yet � admin should add products in the admin panel.</div>
      <?php endif; ?>

      <?php foreach($packages as $p): ?>
        <div class="card">
          <img src="<?php echo htmlspecialchars($p['background'] ?? 'https://iili.io/25NgBx1.jpg'); ?>" alt="">
          <div class="body">
            <h2>
              <?php echo htmlspecialchars($p['name']); ?>
              <?php if (!empty($p['once_per_day'])): ?>
                <span style="background:#ff9800;color:#fff;padding:3px 8px;border-radius:12px;font-size:11px;margin-left:6px;">ONCE/DAY</span>
              <?php endif; ?>
            </h2>
            <p><?php echo htmlspecialchars($p['description']); ?></p>
            <div style="display:flex;align-items:center;margin-top:8px;">
              <div class="price">KSH <?php echo htmlspecialchars($p['price']); ?></div>
              <button type="button" class="buy" data-name="<?php echo htmlspecialchars($p['name']); ?>" data-price="<?php echo htmlspecialchars($p['price']); ?>">Proceed</button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="status">
      <input id="check_no" placeholder="Enter Order number (e.g. FY'S-123456)">
      <button id="checkBtn" type="button">Check Status</button>
      <div class="muted">Copy your Order Number after purchase and keep it safe � you can confirm payment below.</div>
    </div>
  </div>

<script>
/* Client-side: show popup, then POST AJAX to server to SEND STK push.
   Only after user confirms in the popup we call the server (ajax=1).
*/
(function(){
  'use strict';
  function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; }); }

  // Attach listeners on DOM ready
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.buy').forEach(function(btn){
      btn.addEventListener('click', function(e){
        e.preventDefault();
        openBuyDialog(btn.dataset.name, btn.dataset.price);
      });
    });

    var checkBtn = document.getElementById('checkBtn');
    if (checkBtn) {
      checkBtn.addEventListener('click', function(){
        var v = document.getElementById('check_no').value.trim();
        if (!v) return Swal.fire('','Enter order number.','warning');
        // submit to non-AJAX handler (keeps compatibility)
        var f = document.createElement('form');
        f.method = 'post'; f.style.display='none';
        f.innerHTML = '<input name="act" value="check"><input name="check_no" value="'+v+'">';
        document.body.appendChild(f); f.submit();
      });
    }

    // If redirected with showOrderData (non-AJAX fallback), show immediate popup
    <?php if (!empty($showOrderData)): ?>
      setTimeout(function(){ showImmediateOrderPopup(<?php echo json_encode($showOrderData); ?>); }, 120);
    <?php endif; ?>
  });

  // Purchase dialog: collect payer and receiver, then call server via AJAX to initiate STK
  function openBuyDialog(name, price){
    Swal.fire({
      title: `<strong>Buy <span style="color:#0b6623">${escapeHtml(name)}</span></strong>`,
      html: `
        <div style="text-align:left">
          <p style="font-size:16px;margin:6px 0;"><b>KSH ${escapeHtml(price)}</b></p>
          <label style="display:block;text-align:left;margin-top:8px;">M-PESA number you'll pay with</label>
          <input id="payerNo" class="swal2-input" placeholder="07XXXXXXXX" maxlength="10">
          <label style="display:block;text-align:left;margin-top:6px;">Number that will receive the package</label>
          <input id="recvNo" class="swal2-input" placeholder="07XXXXXXXX" maxlength="10">
          <p style="font-size:13px;color:#64748b;margin-top:8px;">Use the <b>paying number</b> to complete the payment. You'll be returned here after payment (or cron/admin will verify later).</p>
        </div>`,
      showCancelButton:true,
      confirmButtonText:'Send M-PESA prompt',
      focusConfirm:false,
      preConfirm: () => {
        const payer = document.getElementById('payerNo').value.trim();
        const recv  = document.getElementById('recvNo').value.trim();
        if(!/^(07|01)\d{8}$/.test(payer)) Swal.showValidationMessage('Enter a valid paying number (07XXXXXXXX)');
        if(!/^(07|01)\d{8}$/.test(recv)) Swal.showValidationMessage('Enter a valid receiver number (07XXXXXXXX)');
        return {payer,recv};
      }
    }).then(async (res) => {
      if (!res.isConfirmed) return;
      // user confirmed in popup -> send AJAX to server to initiate STK
      Swal.fire({ title:'Sending M-PESA prompt...', html:'Please wait', didOpen: ()=>Swal.showLoading(), allowOutsideClick:false });
      try {
        const form = new URLSearchParams();
        form.append('act','init');
        form.append('ajax','1');
        form.append('packageName', name);
        form.append('packagePrice', price);
        form.append('payerNumber', res.value.payer);
        form.append('phoneNumber', res.value.recv);

        const resp = await fetch('', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: form.toString()
        });
        const j = await resp.json();
        Swal.close();
        if (!j || j.status !== 'ok') {
          return Swal.fire('Error', j && j.message ? j.message : 'Payment initiation failed','error');
        }

        // show immediate order popup (client-side) with returned order + checkout details
        const data = {
          order_no: j.order,
          package: j.package,
          receiver: j.receiver,
          payer: j.payer,
          created: j.created,
          checkout: j.checkout
        };
        showImmediateOrderPopup(data);
        // Start automatic polling in background
        if (data.checkout) {
          startAutomaticPolling(data.checkout);
        }
      } catch (e) {
        console.error(e);
        Swal.close();
        Swal.fire('Error','Network or server error while initiating payment.','error');
      }
    });
  }

  // Automatic payment status polling - runs in background even if user closes browser
  function startAutomaticPolling(checkout, maxAttempts = 30, interval = 5000) {
    let attempt = 0;
    
    const poll = async () => {
      attempt++;
      
      try {
        const r = await fetch(`?action=queryStatus&checkout=${encodeURIComponent(checkout)}`);
        const j = await r.json();
        
        if (j && j.update) {
          const status = j.update.status;
          
          if (status === 'paid') {
            const receipt = j.update.receipt || (j.parsed && j.parsed.MpesaReceiptNumber) || 'Paid';
            const amt = (j.parsed && j.parsed.Amount) ? ('KES ' + j.parsed.Amount) : '';
            
            Swal.fire({
              icon: 'success',
              title: 'Payment Successful! 🎉',
              html: `
                <p style="margin:1em 0;"><b>Receipt:</b> ${receipt}</p>
                ${amt ? `<p><b>Amount:</b> ${amt}</p>` : ''}
                <p style="color:#4caf50;margin-top:1em;font-weight:bold;">
                  Your data package will be sent shortly!
                </p>
              `,
              confirmButtonColor: '#4caf50'
            });
            return; // Stop polling
            
          } else if (status === 'failed') {
            Swal.fire({
              icon: 'error',
              title: 'Payment Failed',
              text: j.update.desc || 'Payment was cancelled or failed. Please try again.',
              confirmButtonColor: '#f44336'
            });
            return; // Stop polling
          }
        }
        
        // Continue polling if not finished and haven't exceeded attempts
        if (attempt < maxAttempts) {
          setTimeout(poll, interval);
        }
        
      } catch (e) {
        console.error('Poll error:', e);
        // Continue polling even on error
        if (attempt < maxAttempts) {
          setTimeout(poll, interval);
        }
      }
    };
    
    // Start first poll after initial delay
    setTimeout(poll, interval);
  }

  // Show order popup with Confirm payment and Copy buttons
  window.showImmediateOrderPopup = function(data){
    if (!data) return;
    const html = `
      <div style="text-align:left;line-height:1.4">
        <p><strong style="font-size:1.05em">&#128230; M-PESA Payment Initiated</strong></p>
        <p><b>Package:</b> <strong>${escapeHtml(data.package)}</strong></p>
        <p><b>Receiver (will receive package):</b> <strong>${escapeHtml(data.receiver)}</strong></p>
        <p><b>Paying number (M-PESA):</b> <strong>${escapeHtml(data.payer)}</strong></p>
        <p><b>Order Number:</b> <span id="orderNo" style="font-family:monospace;background:#f3f4f6;padding:6px 8px;border-radius:6px">${escapeHtml(data.order_no)}</span></p>
        <p><b>Created:</b> <strong>${escapeHtml(data.created)}</strong></p>
        <p><b>CheckoutRequestID:</b> <span id="checkout" style="font-family:monospace;background:#f3f4f6;padding:6px 8px;border-radius:6px">${escapeHtml(data.checkout)}</span></p>
        <p style="color:#10b981;font-size:0.95em;font-weight:bold;margin-top:1em">
          Warning ⚠️ Do not close this window until your payment is successfully processed..
        </p>
        <p style="color:#6b7280;font-size:0.90em">Or click <b>CONFIRM PAYMENT</b> below to check status now (after entering M-PESA PIN).</p>
        <div style="margin-top:8px;display:flex;gap:8px">
          <button id="confirmNow" style="padding:8px 12px;border-radius:8px;border:none;background:#10b981;color:#fff;cursor:pointer;font-weight:700">CONFIRM PAYMENT</button>
          <button id="copyOrderBtn" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff;color:#111;cursor:pointer">Copy Order no</button>
        </div>
      </div>
    `;

    Swal.fire({ icon:'info', title:'M-PESA Push Sent', html, showCancelButton:true, allowOutsideClick:false, didOpen: () => {
      const copyBtn = document.getElementById('copyOrderBtn');
      if (copyBtn) copyBtn.addEventListener('click', () => {
        const orderEl = document.getElementById('orderNo');
        if (orderEl) navigator.clipboard.writeText(orderEl.textContent).then(()=> Swal.fire('Copied','Order number copied','success')).catch(()=> Swal.fire('Copy','Unable to copy','info'));
      });

      const confirmBtn = document.getElementById('confirmNow');
      if (confirmBtn) confirmBtn.addEventListener('click', async () => {
        const checkoutId = data.checkout || (document.getElementById('checkout') ? document.getElementById('checkout').textContent : '');
        if (!checkoutId) return Swal.fire('Error','Missing CheckoutRequestID','error');
        Swal.fire({ title:'Checking Safaricom...', html:'Please wait', didOpen: ()=> Swal.showLoading(), allowOutsideClick:false });
        try {
          const r = await fetch(`?action=queryStatus&checkout=${encodeURIComponent(checkoutId)}`);
          const json = await r.json();
          Swal.close();
          if (json && json.update && (json.update.status === 'paid' || json.update.status === 'failed')) {
            if (json.update.status === 'paid') {
              const receipt = json.update.receipt || (json.parsed && json.parsed.MpesaReceiptNumber) || 'Paid';
              const amt = (json.parsed && json.parsed.Amount) ? ('KES ' + json.parsed.Amount) : '';
              Swal.fire({ icon:'success', title:'Payment confirmed', html:`<p><b>Status:</b> ${receipt}</p><p><b>And your order has been sent successful</b> ${amt}</p>` });
            } else {
              Swal.fire({ icon:'error', title:'Transaction not completed', html:`<p>${json.update.desc || 'Transaction failed or cancelled'}</p>` });
            }
            return;
          } else if (json && json.parsed && json.parsed.ResultCode !== null) {
            const p = json.parsed;
            const rc = Number(p.ResultCode);
            if (rc === 0) {
              const receipt = p.MpesaReceiptNumber || 'Success';
              const amt = p.Amount ? ('KES ' + p.Amount) : '';
              Swal.fire({ icon:'success', title:'Payment confirmed', html:`<p><b>Status:</b> ${receipt}</p><p><b>Order has been sent successful</b> ${amt}</p>` });
              return;
            } else {
              Swal.fire({ icon:'error', title:'Transaction not completed', html:`<p>${p.ResultDesc || 'Transaction failed'}</p>` });
              return;
            }
          } else {
            Swal.fire({ icon:'info', title:'Pending', text:'Safaricom has not confirmed this payment yet. Try again later.' });
          }
        } catch(e) {
          console.error(e);
          Swal.close();
          Swal.fire('Error','Network or server error while checking status.','error');
        }
      });
    }});

    // background auto-poll a few times (silent)
    (function autoPoll(checkout, attempts=25, interval=4000) {
      let attempt = 0;
      const poll = async () => {
        attempt++;
        try {
          const r = await fetch(`?action=queryStatus&checkout=${encodeURIComponent(checkout)}`);
          const j = await r.json();
          if (j && j.update && (j.update.status === 'paid' || j.update.status === 'failed')) {
            if (j.update.status === 'paid') {
              const receipt = j.update.receipt || (j.parsed && j.parsed.MpesaReceiptNumber) || 'Paid';
              const amt = (j.parsed && j.parsed.Amount) ? ('KES ' + j.parsed.Amount) : '';
              Swal.fire({ icon:'success', title:'Payment confirmed', html:`<p><b>Status:</b> ${receipt}</p><p><b>To be sent shortly. Thank you</b> ${amt}</p>` });
            }
            return;
          }
        } catch(e){}
        if (attempt < attempts) setTimeout(poll, interval);
      };
      setTimeout(poll, interval);
    })(data.checkout || '');
  };

})();
</script>

<?php
// show server-side swal (if any)
if (!empty($_SESSION['swal'])) {
    $sw = $_SESSION['swal'];
    unset($_SESSION['swal']);
    echo "<script>document.addEventListener('DOMContentLoaded', function(){ try{ Swal.fire(" . json_encode($sw) . "); }catch(e){console.error(e);} });</script>\n";
}
?>

</body>
</html>