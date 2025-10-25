<?php
// payment_checker.php - Background payment status checker
// This runs continuously to check pending payments
date_default_timezone_set('Africa/Nairobi');

$ORD_FILE = __DIR__ . '/orders.json';
$PENDING_FILE = __DIR__ . '/pending_payments.json';
$CFG_FILE = __DIR__ . '/config.json';
$MPESA_LOG = __DIR__ . '/mpesa_logs.json';
$SMS_LOG = __DIR__ . '/sms_logs.json';
$DAILY_FILE = __DIR__ . '/daily_purchases.json';
$PKG_FILE = __DIR__ . '/packages.json';

function load_json($f, $default = []) {
    if (!file_exists($f)) return $default;
    $s = @file_get_contents($f);
    $j = @json_decode($s, true);
    return $j === null ? $default : $j;
}

function save_json($f, $d) {
    return @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT));
}

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

function read_json_locked($file, $default = []) {
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







function mpesa_log($txt) {
    global $MPESA_LOG;
    @file_put_contents($MPESA_LOG, date('c') . ' ' . $txt . PHP_EOL, FILE_APPEND);
}

function sms_log($txt) {
    global $SMS_LOG;
    @file_put_contents($SMS_LOG, date('c') . ' ' . $txt . PHP_EOL, FILE_APPEND);
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

function endpoints_for_env($env) {
    $e = strtolower(trim($env));
    if ($e === 'production' || $e === 'live') {
        return [
            'oauth' => 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
            'query' => 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query'
        ];
    }
    return [
        'oauth' => 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
        'query' => 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query'
    ];
}

function daraja_get_token($consumer_key, $consumer_secret, $env = 'sandbox') {
    $eps = endpoints_for_env($env);
    $url = $eps['oauth'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $consumer_key . ':' . $consumer_secret,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $j = @json_decode($resp, true);
    return $j['access_token'] ?? null;
}

function build_stk_password($businessShortCode, $passkey) {
    $ts = date('YmdHis');
    $pw = base64_encode($businessShortCode . $passkey . $ts);
    return [$pw, $ts];
}

function query_stk_status($checkoutRequestID, $token, $shortcode, $passkey, $env = 'sandbox') {
    $eps = endpoints_for_env($env);
    $url = $eps['query'];
    list($password, $timestamp) = build_stk_password($shortcode, $passkey);
    
    $payload = [
        'BusinessShortCode' => $shortcode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $checkoutRequestID
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $resp = curl_exec($ch);
    curl_close($ch);
    mpesa_log("QUERY_STATUS checkout={$checkoutRequestID} resp=" . ($resp ?: 'error'));
    return @json_decode($resp, true);
}

function parse_query_response($decoded) {
    $out = [
        'ResultCode' => null,
        'ResultDesc' => null,
        'MpesaReceiptNumber' => null,
        'Amount' => null,
        'PhoneNumber' => null,
        'IsPending' => false
    ];
    
    if (!is_array($decoded)) return $out;
    if (isset($decoded['ResultCode'])) $out['ResultCode'] = $decoded['ResultCode'];
    if (isset($decoded['ResultDesc'])) $out['ResultDesc'] = $decoded['ResultDesc'];
    if (isset($decoded['CheckoutRequestID'])) $out['CheckoutRequestID'] = $decoded['CheckoutRequestID'];
    
    // Check if still processing (code 4999 means "transaction is under processing")
    $result_code = $decoded['ResultCode'] ?? null;
    $out['IsPending'] = in_array($result_code, ['4999', 4999]);
    
    if (isset($decoded['CallbackMetadata']['Item']) && is_array($decoded['CallbackMetadata']['Item'])) {
        foreach ($decoded['CallbackMetadata']['Item'] as $item) {
            if (isset($item['Name']) && isset($item['Value'])) {
                $name = $item['Name'];
                $value = $item['Value'];
                if ($name === 'MpesaReceiptNumber') $out['MpesaReceiptNumber'] = $value;
                elseif ($name === 'Amount') $out['Amount'] = $value;
                elseif ($name === 'PhoneNumber') $out['PhoneNumber'] = $value;
            }
        }
    }
    
    return $out;
}

function send_sms($to, $message, $apikey, $partnerID, $shortcode) {
    if (empty($apikey) || empty($partnerID)) {
        sms_log("SMS not sent: credentials missing");
        return false;
    }
    
    $to = preg_replace('/[^0-9]/', '', $to);
    if (substr($to, 0, 1) === '0') $to = '254' . substr($to, 1);
    elseif (substr($to, 0, 3) !== '254') $to = '254' . $to;
    
    $url = 'https://sms.textsms.co.ke/api/services/sendsms/';
    $payload = [
        'apikey' => $apikey,
        'partnerID' => $partnerID,
        'message' => $message,
        'shortcode' => $shortcode,
        'mobile' => $to
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $resp = curl_exec($ch);
    curl_close($ch);
    sms_log("SMS_SEND to={$to} resp=" . ($resp ?: 'error'));
    return true;
}

echo "Payment Checker Started at " . date('Y-m-d H:i:s') . "\n";
mpesa_log("PAYMENT_CHECKER: Started");

while (true) {
    try {
        $pending = read_json_locked($PENDING_FILE, []);
        $orders = read_json_locked($ORD_FILE, []);
        $config = load_json($CFG_FILE, []);
        $packages = load_json($PKG_FILE, []);
        
        if (empty($pending)) {
            sleep(5);
            continue;
        }
        
        $consumer_key = $config['daraja_consumer_key'] ?? '';
        $consumer_secret = $config['daraja_consumer_secret'] ?? '';
        $shortcode = $config['daraja_shortcode'] ?? '';
        $passkey = $config['daraja_passkey'] ?? '';
        $env = $config['daraja_env'] ?? 'sandbox';
        
        if (empty($consumer_key) || empty($consumer_secret) || empty($shortcode) || empty($passkey)) {
            sleep(10);
            continue;
        }
        
        $token = daraja_get_token($consumer_key, $consumer_secret, $env);
        if (!$token) {
            mpesa_log("PAYMENT_CHECKER: Failed to get token");
            sleep(10);
            continue;
        }
        
        // Process all pending payments atomically
        $processed_checkouts = [];
        
        foreach ($pending as $item) {
            $checkout = $item['checkout'] ?? '';
            $order_no = $item['order_no'] ?? '';
            $created = $item['created'] ?? time();
            
            $age = time() - $created;
            
            if ($age > 300) {
                mpesa_log("PAYMENT_CHECKER: Timeout for checkout={$checkout}");
                $processed_checkouts[] = $checkout;
                continue;
            }
            
            $query_result = query_stk_status($checkout, $token, $shortcode, $passkey, $env);
            $parsed = parse_query_response($query_result);
            
            $result_code = $parsed['ResultCode'];
            $is_pending = $parsed['IsPending'] ?? false;
            
            // If still processing (code 4999), skip and check again later
            if ($is_pending) {
                mpesa_log("PAYMENT_CHECKER: Payment PENDING checkout={$checkout} - will check again");
                continue; // Don't mark as processed, will check again on next loop
            }
            
            if ($result_code === 0 || $result_code === '0') {
                $receipt = $parsed['MpesaReceiptNumber'] ?? 'PAID';
                mpesa_log("PAYMENT_CHECKER: Payment SUCCESS checkout={$checkout} receipt={$receipt}");
                
                // Update order atomically
                atomic_update_json($ORD_FILE, function($orders) use ($order_no, $receipt, $config) {
                    foreach ($orders as &$ord) {
                        if ($ord['order_no'] === $order_no) {
                            $ord['status'] = 'paid';
                            $ord['receipt'] = $receipt;
                            $ord['paid_at'] = date('Y-m-d H:i:s');
                            
                            $phone = $ord['phone'] ?? '';
                            $package_name = $ord['package'] ?? '';
                            
                            if (!empty($phone)) {
                                $sms_msg = "";
                                send_sms($phone, $sms_msg, $config['sms_apikey'] ?? '', $config['sms_partnerID'] ?? '', $config['sms_shortcode'] ?? 'TextSMS');
                            }
                            
                            $admin_phone = $config['admin_sms_number'] ?? '';
                            if (!empty($admin_phone)) {
                                $admin_msg = "New payment: {$package_name}, Order: {$order_no}, Receipt: {$receipt}, Phone: {$phone}";
                                send_sms($admin_phone, $admin_msg, $config['sms_apikey'] ?? '', $config['sms_partnerID'] ?? '', $config['sms_shortcode'] ?? 'TextSMS');
                            }
                            
                            break;
                        }
                    }
                    return $orders;
                }, []);
                
                // Record daily purchase for once_per_day packages (only when payment successful)
                // Record receiver phone (not payer) to enforce once-per-day limit per receiver
                $updated_order = read_json_locked($ORD_FILE, []);
                if (isset($updated_order[$order_no])) {
                    $package_name = $updated_order[$order_no]['package'] ?? '';
                    $receiver_phone = $updated_order[$order_no]['phone'] ?? '';
                    
                    foreach ($packages as $pkg) {
                        if ($pkg['name'] === $package_name && !empty($pkg['once_per_day'])) {
                            recordDailyPurchase($receiver_phone, $package_name, $DAILY_FILE);
                            mpesa_log("PAYMENT_CHECKER: DAILY_PURCHASE_RECORDED receiver={$receiver_phone} package={$package_name}");
                            break;
                        }
                    }
                }
                
                $processed_checkouts[] = $checkout;
                continue;
                
            } elseif ($result_code !== null && $result_code != 0 && !$is_pending) {
                // Only mark as failed for actual failure codes (not 4999 = still processing)
                mpesa_log("PAYMENT_CHECKER: Payment FAILED checkout={$checkout} code={$result_code}");
                
                // Update order atomically
                atomic_update_json($ORD_FILE, function($orders) use ($order_no, $parsed) {
                    foreach ($orders as &$ord) {
                        if ($ord['order_no'] === $order_no) {
                            $ord['status'] = 'failed';
                            $ord['result_desc'] = $parsed['ResultDesc'] ?? 'Payment failed';
                            break;
                        }
                    }
                    return $orders;
                }, []);
                
                $processed_checkouts[] = $checkout;
                continue;
            }
        }
        
        // Remove processed items atomically
        if (!empty($processed_checkouts)) {
            atomic_update_json($PENDING_FILE, function($pending) use ($processed_checkouts) {
                return array_values(array_filter($pending, function($item) use ($processed_checkouts) {
                    return !in_array($item['checkout'] ?? '', $processed_checkouts);
                }));
            }, []);
        }
        
        sleep(3);
        
    } catch (Exception $e) {
        mpesa_log("PAYMENT_CHECKER ERROR: " . $e->getMessage());
        sleep(5);
    }
}
