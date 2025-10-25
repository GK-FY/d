<?php
// fy.php — Admin Panel (Daraja / TextSMS settings editable) with "Check pending payments now"
// Full admin UI restored (products, orders, settings), plus check-pending/cron.
session_start();
date_default_timezone_set('Africa/Nairobi');

// --- SECRET TO REVEAL FULL SETTINGS (as requested) ---
define('VIEW_SECRET', 'GkFy4262@#$Fy');

// Credentials - keep secure or move to env for production
define('ADMIN_USER','Shank.Fy');
define('ADMIN_PASS','GkFy4262@#$Fy');

// Logout
if(isset($_GET['logout'])) {
    session_destroy();
    header('Location:fy.php');
    exit;
}

// LOGIN
if(!isset($_SESSION['admin'])) {
    $err = '';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if($_POST['user']===ADMIN_USER && $_POST['pass']===ADMIN_PASS){
            $_SESSION['admin']=true;
            header('Location:fy.php'); exit;
        } else { $err='Invalid credentials.'; }
    }
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Login</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
      body{margin:0;height:100vh;display:flex;align-items:center;justify-content:center;
        background:linear-gradient(135deg,#7b1fa2,#ab47bc);font-family:'Segoe UI',sans-serif;}
      .login-card{background:#fff;padding:2em;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,0.2);
        width:320px;text-align:center;}
      .login-card h2{margin-bottom:1em;color:#7b1fa2;}
      .login-card input{width:100%;padding:10px;margin:.5em 0;border:1px solid #ccc;border-radius:6px;}
      .login-card button{width:100%;padding:10px;background:#7b1fa2;color:#fff;
        border:none;border-radius:6px;font-size:1em;cursor:pointer;}
      .login-card button:hover{background:#6a1b9a;}
    </style>
    </head><body>
      <div class="login-card">
        <h2>&#128272; Admin Login</h2>
        <form method="post">
          <input name="user" placeholder="Username" required>
          <input name="pass" type="password" placeholder="Password" required>
          <button>Login</button>
        </form>
      </div>
      <?php if($err): ?>
        <script>Swal.fire('Error','<?=$err?>','error');</script>
      <?php endif;?>
    </body></html>
    <?php exit;
}

// Paths
$PKG = __DIR__.'/packages.json';
$ORD = __DIR__.'/orders.json';
$CFG = __DIR__.'/config.json';
$MPESA_LOG = __DIR__.'/mpesa_logs.json';
$SMS_LOG = __DIR__.'/sms_logs.json';

// Load data safely
function load_json($f,$def=[]){ if(!file_exists($f)) return $def; $s=@file_get_contents($f); $d=@json_decode($s,true); return $d===null?$def:$d; }
function save_json($f,$d){ return @file_put_contents($f,json_encode($d, JSON_PRETTY_PRINT)); }

$packages = load_json($PKG,[]);
$orders   = load_json($ORD,[]);
$config   = load_json($CFG,[]);

// defaults for settings
$config += [
  'logo_text'=>'FY\'S PROPERTY',
  'background_url'=>'https://iili.io/25NgBx1.jpg',
  'daraja_env' => 'sandbox',
  'daraja_consumer_key' => '',
  'daraja_consumer_secret' => '',
  'daraja_shortcode' => '',   // STORE SHORTCODE (MANDATORY when editing daraja settings)
  'daraja_passkey' => '',
  'daraja_callback' => '',
  'default_transaction_type' => 'CustomerPayBillOnline',
  'default_account_reference' => '',
  'paybill_number' => '',
  'till_number' => '',
  'mpesa_number'=>'',
  'instruction_msg'=>'Pay with {mpesa} for order {order}',
  'admin_sms_number'=>'0700363422',
  'sms_partnerID'=>'',
  'sms_apikey'=>'',
  'sms_shortcode'=>'TextSMS',
  'cron_key' => ''
];

function mpesa_log($txt){ global $MPESA_LOG; @file_put_contents($MPESA_LOG, date('c').' '.$txt.PHP_EOL, FILE_APPEND); }
function sms_log($txt){ global $SMS_LOG; @file_put_contents($SMS_LOG, date('c').' '.$txt.PHP_EOL, FILE_APPEND); }

// Daraja helpers (same as index functions; duplicated for self-contained admin)
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
    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    mpesa_log("ADMIN_TOKEN_RESPONSE: ".($resp?:$err));
    $j = @json_decode($resp, true);
    return $j['access_token'] ?? null;
}
function build_stk_password($businessShortCode, $passkey){
    $ts = date('YmdHis'); $pw = base64_encode($businessShortCode . $passkey . $ts); return [$pw, $ts];
}
function parse_query_response($decoded) {
    $out = ['ResultCode'=>null,'ResultDesc'=>null,'MpesaReceiptNumber'=>null,'Amount'=>null,'PhoneNumber'=>null,'CheckoutRequestID'=>null,'raw'=>$decoded];
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
            $n = strtolower($it['Name'] ?? ''); $v = $it['Value'] ?? null;
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
function daraja_query_checkout_admin($config, $checkoutId) {
    $env = $config['daraja_env'] ?? 'sandbox';
    $token = daraja_get_token($config['daraja_consumer_key'] ?? '', $config['daraja_consumer_secret'] ?? '', $env);
    if (!$token) { mpesa_log("ADMIN_QUERY_NO_TOKEN checkout={$checkoutId}"); return ['error'=>'no_token']; }
    $eps = endpoints_for_env($env);
    list($password, $timestamp) = build_stk_password($config['daraja_shortcode'] ?? '', $config['daraja_passkey'] ?? '');
    $payload = ["BusinessShortCode" => $config['daraja_shortcode'], "Password" => $password, "Timestamp" => $timestamp, "CheckoutRequestID" => $checkoutId];
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
    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    mpesa_log("ADMIN_QUERY_HTTP resp=".($resp?:$err));
    $decoded = @json_decode($resp, true);
    if (!$decoded) return ['error'=>'invalid_response','raw'=>$resp];
    $parsed = parse_query_response($decoded);
    return ['ok'=>true,'parsed'=>$parsed,'raw'=>$decoded];
}
function update_order_from_parsed_admin($checkoutId, $parsed, &$orders, $config, $ORD_FILE) {
    $foundKey = null;
    foreach ($orders as $k=>$o) {
        if (!empty($o['daraja_checkout']) && $o['daraja_checkout'] === $checkoutId) { $foundKey = $k; break; }
    }
    if (!$foundKey) { mpesa_log("ADMIN_UPDATE_NO_ORDER checkout={$checkoutId}"); return ['error'=>'no_order']; }
    $rc = $parsed['ResultCode']; $desc = $parsed['ResultDesc'] ?? '';
    if ($rc === null) return ['status'=>'no_result'];
    if ((int)$rc === 0) {
        $receipt = $parsed['MpesaReceiptNumber'] ?? null; $amount = $parsed['Amount'] ?? null; $phone = $parsed['PhoneNumber'] ?? null;
        $orders[$foundKey]['status'] = 'paid'; $orders[$foundKey]['paid_at'] = date('Y-m-d H:i:s');

        if ($receipt) $orders[$foundKey]['mpesa_receipt'] = $receipt;
        if ($amount) $orders[$foundKey]['paid_amount'] = $amount;
        if ($phone) $orders[$foundKey]['paid_phone'] = $phone;
        save_json($ORD_FILE, $orders);
        // send SMS
        $admin = $config['admin_sms_number'] ?? '0700363422'; $custNo = $orders[$foundKey]['phone'] ?? '';
        $amtText = number_format((float)($orders[$foundKey]['paid_amount'] ?? $orders[$foundKey]['amount']), 2);
        $txref = $orders[$foundKey]['order_no']; $dt = date('n/j/y'); $tm = date('g:i A'); $newbal = number_format((mt_rand(1000,50000))/100, 2);
        $sms_message = "{$txref} Confirmed.You have received Ksh{$amtText} from BINGWA  CUSTOMER {$custNo} on {$dt} at {$tm}  New M-PESA balance is Ksh{$newbal}. Earn interest daily on Ziidi MMF,Dial *334#";
        if (!empty($config['sms_apikey']) && !empty($config['sms_partnerID'])) {
            $payload = json_encode(['apikey'=>$config['sms_apikey'],'partnerID'=>$config['sms_partnerID'],'message'=>$sms_message,'shortcode'=>$config['sms_shortcode'] ?? 'TextSMS','mobile'=> preg_match('/^(07|01)\d{8}$/',$admin) ? '254'.substr(preg_replace('/\D/','',$admin),1) : preg_replace('/\D/','',$admin)]);
            $ch = curl_init('https://sms.textsms.co.ke/api/services/sendsms/');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>20 ]);
            $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch); sms_log("ADMIN_SMS_RESP:".($resp?:$err));
        } else {
            sms_log("ADMIN_SMS_SKIPPED MSG:{$sms_message}");
        }
        mpesa_log("ADMIN_ORDER_PAID order={$foundKey} checkout={$checkoutId}");
        return ['status'=>'paid','order'=>$foundKey,'receipt'=>$receipt];
    } else {
        $orders[$foundKey]['status'] = 'failed'; $orders[$foundKey]['failed_at'] = date('Y-m-d H:i:s'); $orders[$foundKey]['failed_desc'] = $desc; save_json($ORD_FILE, $orders);
        mpesa_log("ADMIN_ORDER_FAILED order={$foundKey} checkout={$checkoutId} desc={$desc}");
        return ['status'=>'failed','order'=>$foundKey,'desc'=>$desc];
    }
}

// --- Handle unlocking/locking of full settings ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_secret'])) {
    $attempt = $_POST['secret_key'] ?? '';
    if ($attempt === VIEW_SECRET) {
        $_SESSION['show_all_settings'] = true;
        $_SESSION['msg'] = 'Full settings unlocked.';
    } else {
        $_SESSION['msg'] = 'Incorrect secret key.';
    }
    header('Location:fy.php?view=settings'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_secret'])) {
    unset($_SESSION['show_all_settings']);
    $_SESSION['msg'] = 'Full settings locked.';
    header('Location:fy.php?view=settings'); exit;
}

// Save handlers
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(isset($_POST['addPkg'])){
        $packages[]=[
            'name'=>$_POST['name'],
            'price'=>$_POST['price'],
            'description'=>$_POST['desc'],
            'background'=>$_POST['bg'],
            'once_per_day'=>isset($_POST['once_per_day']) ? true : false
        ];
        save_json($PKG,$packages);
        $_SESSION['msg']='Product added.'; header('Location:fy.php?view=manage'); exit;
    }
    if(isset($_POST['editPkg'])){
        $i=intval($_POST['index']);
        if(isset($packages[$i])){
            $packages[$i]=[
                'name'=>$_POST['name'],
                'price'=>$_POST['price'],
                'description'=>$_POST['desc'],
                'background'=>$_POST['bg'],
                'once_per_day'=>isset($_POST['once_per_day']) ? true : false,
            'once_per_day'=>isset($_POST['once_per_day']) ? true : false
            ];
            save_json($PKG,$packages);
            $_SESSION['msg']='Product updated.';
        }
        header('Location:fy.php?view=manage'); exit;
    }
    if(isset($_POST['deletePkg'])){
        $i=intval($_POST['index']);
        if(isset($packages[$i])){ array_splice($packages,$i,1); save_json($PKG,$packages); $_SESSION['msg']='Product deleted.';}
        header('Location:fy.php?view=manage'); exit;
    }
    if(isset($_POST['order_no'],$_POST['status'])){
        $ord=$_POST['order_no'];
        $orders = load_json($ORD, []);
        if(isset($orders[$ord])){
          $orders[$ord]['status'] = $_POST['status'];
          $orders[$ord]['reason'] = trim($_POST['reason']);
          if($_POST['status'] === 'paid'){
            $orders[$ord]['paid_at'] = date('Y-m-d H:i:s');
            // optional: send SMS if creds present (keeps previous behavior)
            $admin = $config['admin_sms_number'] ?? '0700363422';
            $custNo = $orders[$ord]['phone'] ?? '';
            $amt = number_format((float)$orders[$ord]['amount'],2);
            $txref = $orders[$ord]['order_no'];
            $dt = date('n/j/y');
            $tm = date('g:i A');
            $newbal = number_format((mt_rand(1000,50000))/100,2);
            $message = "{$txref} Confirmed.You have received Ksh{$amt} from BINGWA  CUSTOMER {$custNo} on {$dt} at {$tm}  New M-PESA balance is Ksh{$newbal}. Earn interest daily on Ziidi MMF,Dial *334#";
            if(!empty($config['sms_apikey']) && !empty($config['sms_partnerID'])){
              $payload = json_encode([
                'apikey'=>$config['sms_apikey'],
                'partnerID'=>$config['sms_partnerID'],
                'message'=>$message,
                'shortcode'=>$config['sms_shortcode'] ?? 'TextSMS',
                'mobile'=> preg_match('/^(07|01)\d{8}$/',$admin) ? '254'.substr(preg_replace('/\D/','',$admin),1) : preg_replace('/\D/','',$admin)
              ]);
              $ch = curl_init('https://sms.textsms.co.ke/api/services/sendsms/');
              curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
                CURLOPT_POSTFIELDS=>$payload,
                CURLOPT_TIMEOUT=>20
              ]);
              $resp = curl_exec($ch); $err = curl_error($ch);
              curl_close($ch);
              @file_put_contents(__DIR__.'/sms_logs.json', date('c')." MANUAL PAY SMS:".($resp?:$err).PHP_EOL, FILE_APPEND);
            }
          }
          save_json($ORD,$orders);
          $_SESSION['msg']="Order {$ord} updated.";
        } else {
          $_SESSION['msg']="Order not found.";
        }
        header('Location:fy.php?view=orders'); exit;
    }

    // Save settings, but only allow editing Daraja fields when unlocked
    if(isset($_POST['saveCfg'])){
        // Build list of fields always allowed to save
        $allowed = ['logo_text','background_url','mpesa_number','instruction_msg','admin_sms_number','sms_partnerID','sms_apikey','sms_shortcode','default_transaction_type','default_account_reference','paybill_number','till_number','cron_key'];
        // If unlocked, allow daraja fields too
        $unlocked = !empty($_SESSION['show_all_settings']);
        if ($unlocked) {
            $allowed = array_merge($allowed, ['daraja_env','daraja_consumer_key','daraja_consumer_secret','daraja_shortcode','daraja_passkey','daraja_callback']);
        }

        foreach($allowed as $k){
            $config[$k] = $_POST[$k] ?? $config[$k] ?? '';
        }

        // validation: only require daraja_shortcode if unlocked (because daraja config is hidden otherwise)
        if ($unlocked && (empty($config['daraja_shortcode']) || !preg_match('/^\d+$/', $config['daraja_shortcode']))) {
            $_SESSION['msg']='Daraja Shortcode (store number) is required and must be digits only when editing Daraja settings.';
        } else if (!empty($config['paybill_number']) && !preg_match('/^\d+$/', $config['paybill_number'])) $_SESSION['msg']='Paybill must be digits only.';
        else if (!empty($config['till_number']) && !preg_match('/^\d+$/', $config['till_number'])) $_SESSION['msg']='Till must be digits only.';
        else {
            save_json($CFG,$config);
            $_SESSION['msg']='Settings saved.';
        }
        header('Location:fy.php?view=settings'); exit;
    }
}

// Dashboard run pending (admin trigger)
if (isset($_GET['run']) && $_GET['run'] === 'check_pending') {
    $orders = load_json($ORD, []);
    $pending = [];
    foreach ($orders as $k=>$o) {
        if (($o['status'] ?? '') === 'pending' && !empty($o['daraja_checkout'])) $pending[$k] = $o;
    }
    $summary = ['checked'=>0,'updated'=>0,'errors'=>[]];
    foreach ($pending as $k=>$o) {
        $checkout = $o['daraja_checkout'];
        $summary['checked']++;
        $res = daraja_query_checkout_admin($config, $checkout);
        if (isset($res['error'])) { $summary['errors'][] = "checkout={$checkout} err=".$res['error']; continue; }
        $parsed = $res['parsed'] ?? null;
        $orders = load_json($ORD, []);
        $u = update_order_from_parsed_admin($checkout, $parsed, $orders, $config, $ORD);
        if ($u['status'] === 'paid' || $u['status'] === 'failed') $summary['updated']++;
    }
    $_SESSION['msg'] = "Checked {$summary['checked']} pending; updated {$summary['updated']}.";
    header('Location:fy.php'); exit;
}

// Views
$view = $_GET['view'] ?? 'dashboard';
$search = trim($_GET['search'] ?? '');

?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Panel</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--primary:#7b1fa2;--light:#f3e5f5;}
body{margin:0;font-family:'Segoe UI',sans-serif;background:var(--light);}
header{height:60px;background:var(--primary);color:#fff;display:flex;align-items:center;
  padding:0 1em;position:fixed;top:0;left:0;right:0;z-index:100;}
.hamburger{cursor:pointer;font-size:1.5em;margin-right:1em;}
.title{font-size:1.2em;}
.sidebar{position:fixed;top:60px;left:-220px;width:220px;height:100%;background:var(--primary);
  color:#fff;transition:left .3s;overflow:auto;padding-top:1em;}
.sidebar.show{left:0;}
.sidebar a{display:block;padding:.8em 1em;color:#fff;text-decoration:none;}
.sidebar a:hover{background:rgba(255,255,255,0.1);}
.main{margin-top:60px;padding:1.5em;transition:margin-left .3s;}
.main.shift{margin-left:220px;}
.msg{background:#e1bee7;color:var(--primary);padding:.8em;border-radius:6px;margin-bottom:1em;}
.card{background:#fff;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);
  margin-bottom:1em;overflow:hidden;}
.card-header{background:var(--light);padding:.8em 1em;font-weight:bold;color:var(--primary);}
.card-body{padding:1em;}
input,textarea,select,button{font-family:inherit;margin:.4em 0;}
input,textarea,select{width:100%;padding:.6em;border:1px solid #ccc;border-radius:4px;}
.btn{padding:.6em 1.2em;border:none;border-radius:4px;cursor:pointer;}
.btn-primary{padding:.6em 1.2em;border:none;border-radius:4px;cursor:pointer;background:var(--primary);color:#fff;}
.btn-danger{background:#d32f2f;color:#fff;}
.btn-sm{font-size:.85em;padding:.3em .6em;}
table{width:100%;border-collapse:collapse;margin-top:1em;}
th,td{padding:.6em;border:1px solid #ddd;text-align:left;}
th{background:var(--light);}
.search-bar{display:flex;gap:.5em;margin-bottom:1em;}
.small-note{font-size:.9em;color:#555;margin:6px 0;}
.lock-box{border:1px dashed #ddd;padding:12px;border-radius:6px;background:#fff;}
</style>
</head><body>

<header>
  <span class="hamburger" onclick="toggleSidebar()">&#9776;</span>
  <span class="title">Admin Panel</span>
</header>

<nav id="sidebar" class="sidebar">
  <a href="fy.php">Dashboard</a>
  <a href="fy.php?view=manage">Manage Products</a>
  <a href="fy.php?view=orders">Manage Orders</a>
  <a href="fy.php?view=settings">Settings</a>
  <a href="fy.php?logout=1">Logout</a>
</nav>

<main id="main" class="main">
  <?php if(!empty($_SESSION['msg'])): ?>
    <div class="msg"><?= $_SESSION['msg']; unset($_SESSION['msg']); ?></div>
  <?php endif; ?>

  <?php if($view==='dashboard'): ?>
    <h2>Welcome, Admin &#128075;</h2>
    <div class="card"><div class="card-header">Quick Stats</div>
      <div class="card-body">
        <p>Total Products: <strong><?=count($packages)?></strong></p>
        <p>Total Orders: <strong><?=count($orders)?></strong></p>
        <p>Daraja Env: <strong><?=htmlspecialchars($config['daraja_env'])?></strong></p>
        <p>Daraja Shortcode: <strong><?=htmlspecialchars($config['daraja_shortcode'])?:'<em>Not set</em>'?></strong></p>
        <p style="margin-top:12px;">
          <a href="fy.php?run=check_pending" class="btn btn-primary btn-sm">Check pending payments now</a>
          <span style="font-size:13px;color:#666;margin-left:10px">(Checks orders with daraja_checkout)</span>
        </p>
      </div>
    </div>

  <?php elseif($view==='manage'): ?>
    <h2>Manage Products</h2>
    <button class="btn btn-primary btn-sm" onclick="location='fy.php?view=manage&edit=0'">+ Add New Product</button>
    <?php
      $editIndex = intval($_GET['edit'] ?? -1);
      if($editIndex>=0):
        $prod = $editIndex===0 ? ['name'=>'','price'=>'','description'=>'','background'=>''] : ($packages[$editIndex] ?? ['name'=>'','price'=>'','description'=>'','background'=>'']);
    ?>
      <div class="card"><div class="card-header"><?= $editIndex===0 ? 'Add Product':'Edit Product' ?></div><div class="card-body">
        <form method="post">
          <?php if($editIndex>0): ?><input type="hidden" name="index" value="<?=($editIndex)?>"><button name="editPkg" class="btn btn-primary btn-sm">Save Changes</button><?php else: ?><button name="addPkg" class="btn btn-primary btn-sm">Create Product</button><?php endif; ?>
          <label>Name</label><input name="name" value="<?=htmlspecialchars($prod['name'])?>" required>
          <label>Price</label><input name="price" value="<?=htmlspecialchars($prod['price'])?>" required>
          <label>Description</label><textarea name="desc" rows="3"><?=htmlspecialchars($prod['description'])?></textarea>
          <label>Background URL</label><input name="bg" value="<?=htmlspecialchars($prod['background'])?>">
          <label style="display:flex;align-items:center;margin-top:12px;">
            <input type="checkbox" name="once_per_day" <?=!empty($prod['once_per_day'])?'checked':''?> style="width:auto;margin-right:8px;">
            <span>ðŸ”’ Once per day (users can only buy this package once every 24 hours)</span>
          </label>
        </form>
      </div></div>
    <?php endif; ?>

    <?php if(!isset($_GET['edit'])): foreach($packages as $i=>$p): ?>
      <div class="card"><div class="card-header">
        <?=htmlspecialchars($p['name'])?> <?=!empty($p['once_per_day'])?'<span style="background:#ff9800;color:#fff;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:6px;">ONCE/DAY</span>':''?>
        <button class="btn btn-sm btn-primary" style="float:right;margin-left:4px;" onclick="location='fy.php?view=manage&edit=<?=$i?>'">&#9999;&#65039; Edit</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete product?')">
          <input type="hidden" name="index" value="<?=$i?>">
          <button name="deletePkg" class="btn btn-sm btn-danger" style="float:right;">&#128465;&#65039; Delete</button>
        </form>
      </div></div>
    <?php endforeach; endif; ?>

  <?php elseif($view==='orders'): ?>
    <h2>Manage Orders</h2>
    <form method="get" class="search-bar"><input type="hidden" name="view" value="orders"><input name="search" placeholder="Search Order #" value="<?=htmlspecialchars($search)?>"><button class="btn btn-primary btn-sm">Search</button><button class="btn btn-sm" onclick="location='fy.php?view=orders';return false;">Reset</button></form>
    <table>
      <tr><th>Order#</th><th>Package</th><th>Phone (Receiver)</th><th>Payer</th><th>Amt</th><th>Status</th><th>Reason</th><th>Created</th><th>Action</th></tr>
      <?php
        $list = $search ? array_filter($orders, fn($o)=>stripos($o['order_no'],$search)!==false) : $orders;
        foreach($list as $o): ?>
      <tr>
        <td><?=$o['order_no']?></td>
        <td><?=htmlspecialchars($o['package'])?></td>
        <td><?=htmlspecialchars($o['phone'] ?? '')?></td>
        <td><?=htmlspecialchars($o['payer'] ?? '')?></td>
        <td>KSH <?=$o['amount']?></td>
        <td><?=ucfirst($o['status'])?></td>
        <td><?=htmlspecialchars($o['reason'] ?? '')?></td>
        <td><?=$o['created']?></td>
        <td>
          <form method="post" style="display:grid;grid-template-columns:1fr auto;gap:.3em;">
            <input type="hidden" name="order_no" value="<?=$o['order_no']?>">
            <select name="status">
              <option value="pending"   <?=$o['status']==='pending'   ?'selected':''?>>&#9203; Pending</option>
              <option value="paid"      <?=$o['status']==='paid'      ?'selected':''?>>&#128176; Paid</option>
              <option value="cancelled" <?=$o['status']==='cancelled'?'selected':''?>>&#10060; Cancelled</option>
              <option value="delivered" <?=$o['status']==='delivered'?'selected':''?>>&#128230; Delivered</option>
              <option value="refunded"  <?=$o['status']==='refunded' ?'selected':''?>>&#128184; Refunded</option>
            </select>
            <textarea name="reason" rows="1" placeholder="Reason…"><?=htmlspecialchars($o['reason'] ?? '')?></textarea>
            <button class="btn btn-primary btn-sm">Update</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

  <?php elseif($view==='settings'): ?>
    <h2>Site & Payment Settings</h2>
    <div class="card"><div class="card-header">Settings</div><div class="card-body">

      <!-- Reveal / Lock full settings -->
      <?php if(empty($_SESSION['show_all_settings'])): ?>
        <div class="lock-box" style="margin-bottom:12px;">
          <strong>Limited settings mode</strong>
          <div class="small-note">Daraja credentials are hidden for testers. To view or edit the full settings (Daraja credentials & callbacks), enter the secret key below.</div>
          <form method="post" style="display:flex;gap:.5em;align-items:center;">
            <input name="secret_key" placeholder="Enter secret key to unlock" style="flex:1">
            <button name="unlock_secret" class="btn btn-primary btn-sm">Unlock</button>
          </form>
        </div>
      <?php else: ?>
        <div class="lock-box" style="margin-bottom:12px;">
          <strong>Full settings visible</strong>
          <div class="small-note">You have unlocked all settings including Daraja credentials. Click lock to hide them again.</div>
          <form method="post" style="display:inline;">
            <button name="lock_secret" class="btn btn-sm">Lock Full Settings</button>
          </form>
        </div>
      <?php endif; ?>

      <form method="post">
        <button name="saveCfg" class="btn btn-primary btn-sm" style="float:right;">Save Settings</button>

        <!-- Non-Daraja fields (visible always) -->
        <label>Site Title</label><input name="logo_text" value="<?=htmlspecialchars($config['logo_text'])?>">
        <label>Background URL</label><input name="background_url" value="<?=htmlspecialchars($config['background_url'])?>">
        <label>M-PESA Number (optional)</label><input name="mpesa_number" value="<?=htmlspecialchars($config['mpesa_number'])?>">
        <label>Instruction Msg ({mpesa},{order})</label><textarea name="instruction_msg" rows="3"><?=htmlspecialchars($config['instruction_msg'])?></textarea>

        <div style="border-top:1px solid #eee;margin:14px 0;"></div>
        <h3 style="margin:.4em 0;color:#7b1fa2;">Admin SMS & TextSMS API</h3>
        <label>Admin SMS Number (receives alerts)</label><input name="admin_sms_number" value="<?=htmlspecialchars($config['admin_sms_number'])?>">
        <label>TextSMS Partner ID</label><input name="sms_partnerID" value="<?=htmlspecialchars($config['sms_partnerID'])?>">
        <label>TextSMS API Key</label><input name="sms_apikey" value="<?=htmlspecialchars($config['sms_apikey'])?>">
        <label>TextSMS Sender ID / Shortcode</label><input name="sms_shortcode" value="<?=htmlspecialchars($config['sms_shortcode'])?>">

        <div style="border-top:1px solid #eee;margin:14px 0;"></div>
        <h3 style="margin:.4em 0;color:#7b1fa2;">Daraja (M-PESA)</h3>

        <!-- Daraja fields hidden unless unlocked -->
        <?php if(!empty($_SESSION['show_all_settings'])): ?>
          <label>Environment</label>
          <select name="daraja_env">
            <option value="sandbox" <?=$config['daraja_env']==='sandbox'?'selected':''?>>Sandbox</option>
            <option value="live"    <?=$config['daraja_env']==='live'   ?'selected':''?>>Live</option>
          </select>

          <label>Daraja Consumer Key</label><input name="daraja_consumer_key" value="<?=htmlspecialchars($config['daraja_consumer_key'])?>">
          <label>Daraja Consumer Secret</label><input name="daraja_consumer_secret" value="<?=htmlspecialchars($config['daraja_consumer_secret'])?>">
          <label>Daraja Shortcode (STORE NUMBER) <span style="color:#b32d2d;font-weight:700">(REQUIRED)</span></label>
          <input name="daraja_shortcode" value="<?=htmlspecialchars($config['daraja_shortcode'])?>">
          <div style="margin-bottom:8px;color:#555;font-size:.9em">This is the store shortcode used to build the STK password. It must be set (digits only) when editing Daraja settings.</div>

          <label>Daraja Passkey</label><input name="daraja_passkey" value="<?=htmlspecialchars($config['daraja_passkey'])?>">
          <label>Callback URL (optional) — must be public and HTTPS for production</label><input name="daraja_callback" value="<?=htmlspecialchars($config['daraja_callback'])?>">
        <?php else: ?>
          <div class="small-note">Daraja credentials are hidden. Unlock full settings to view or edit them.</div>
        <?php endif; ?>

        <div style="border-top:1px solid #eee;margin:14px 0;"></div>

        <label>Default Transaction Type</label>
        <select name="default_transaction_type">
          <option value="CustomerPayBillOnline" <?=$config['default_transaction_type']==='CustomerPayBillOnline'?'selected':''?>>CustomerPayBillOnline (Paybill)</option>
          <option value="CustomerBuyGoodsOnline" <?=$config['default_transaction_type']==='CustomerBuyGoodsOnline'?'selected':''?>>CustomerBuyGoodsOnline (Till)</option>
        </select>

        <label>Default Account Reference</label><input name="default_account_reference" value="<?=htmlspecialchars($config['default_account_reference'])?>">

        <label>Paybill Number (optional) — used as PartyB when transaction type = Paybill</label><input name="paybill_number" value="<?=htmlspecialchars($config['paybill_number'])?>">
        <label>Till Number (optional) — used as PartyB when transaction type = Till</label><input name="till_number" value="<?=htmlspecialchars($config['till_number'])?>">

        <label style="margin-top:10px">Cron Key (optional)</label>
        <input name="cron_key" value="<?=htmlspecialchars($config['cron_key'])?>">
        <div style="margin-top:8px;color:#555;font-size:.9em;">If set, use this key when calling the cron endpoint: <code>?action=cronCheckPending&key=YOUR_CRON_KEY</code></div>

      </form>
    </div></div>
  <?php endif; ?>

</main>

<script>
function toggleSidebar(){ document.getElementById('sidebar').classList.toggle('show'); document.getElementById('main').classList.toggle('shift'); }
</script>
</body>
</html>
