<?php
$index = file_get_contents('index.php');

// Find and replace the entire buyPackage function with enhanced version
$old_buy_start = '  window.buyPackage = function(i) {';
$old_buy_end = '  };';

// Find the position of the buyPackage function
$start_pos = strpos($index, $old_buy_start);
if ($start_pos === false) {
    echo "Could not find buyPackage function\n";
    exit(1);
}

// Find the closing of the function (look for the next standalone })
$search_from = $start_pos + strlen($old_buy_start);
$brace_count = 1;
$end_pos = $search_from;

while ($brace_count > 0 && $end_pos < strlen($index)) {
    if ($index[$end_pos] === '{') $brace_count++;
    if ($index[$end_pos] === '}') $brace_count--;
    $end_pos++;
}

$new_buy_function = '  window.buyPackage = async function(i) {
    const pkg = packages[i];
    if (!pkg) return;
    
    const { value: formData } = await Swal.fire({
      title: pkg.name,
      html: `
        <div style="text-align:left;margin:1em 0;">
          <p><b>Price:</b> KES ${pkg.price}</p>
          <p style="font-size:0.9em;color:#666;">${pkg.description}</p>
        </div>
        <input id="swal-phone" class="swal2-input" placeholder="Phone (07xxxxxxxx)" required>
      `,
      focusConfirm: false,
      showCancelButton: true,
      confirmButtonText: \'Pay Now\',
      preConfirm: () => {
        const phone = document.getElementById(\'swal-phone\').value;
        if (!phone || phone.length < 9) {
          Swal.showValidationMessage(\'Please enter a valid phone number\');
          return false;
        }
        return { phone, pkg_index: i };
      }
    });

    if (!formData) return;

    // Show loading immediately
    Swal.fire({
      title: \'Processing...\',
      html: \'Initiating payment...\',
      allowOutsideClick: false,
      didOpen: () => { Swal.showLoading(); }
    });

    try {
      const buyResp = await fetch(`?action=buyNow&pkg=${i}&phone=${encodeURIComponent(formData.phone)}`);
      const buyData = await buyResp.json();

      if (buyData.error) {
        Swal.close();
        if (buyData.error === \'daily_limit\') {
          Swal.fire({
            icon: \'warning\',
            title: \'Daily Limit Reached\',
            text: buyData.message || \'You have already purchased this package today. Please try again tomorrow.\',
            confirmButtonColor: \'#ff9800\'
          });
        } else {
          Swal.fire(\'Error\', buyData.message || buyData.error, \'error\');
        }
        return;
      }

      if (buyData.ok && buyData.checkout) {
        const checkout = buyData.checkout;
        
        // Inform user they can close the window
        Swal.fire({
          icon: \'info\',
          title: \'Payment Sent to Phone\',
          html: `
            <p>Check your phone and enter your M-PESA PIN.</p>
            <p style="margin-top:1em;font-size:0.9em;color:#666;">
              ✨ You can close this page - we\'ll process your payment automatically!
            </p>
          `,
          timer: 5000,
          timerProgressBar: true,
          showConfirmButton: true,
          confirmButtonText: \'OK, Got it\'
        });

        // Start automatic polling in background
        startAutomaticPolling(checkout);
      } else {
        Swal.close();
        Swal.fire(\'Error\', buyData.message || \'Payment initiation failed\', \'error\');
      }
    } catch (e) {
      console.error(e);
      Swal.close();
      Swal.fire(\'Error\', \'Network error. Please try again.\', \'error\');
    }
  };

  // Automatic payment status polling
  function startAutomaticPolling(checkout, maxAttempts = 20, interval = 5000) {
    let attempt = 0;
    
    const poll = async () => {
      attempt++;
      
      try {
        const r = await fetch(`?action=queryStatus&checkout=${encodeURIComponent(checkout)}`);
        const j = await r.json();
        
        if (j && j.update) {
          const status = j.update.status;
          
          if (status === \'paid\') {
            const receipt = j.update.receipt || (j.parsed && j.parsed.MpesaReceiptNumber) || \'Paid\';
            const amt = (j.parsed && j.parsed.Amount) ? (\'KES \' + j.parsed.Amount) : \'\';
            
            Swal.fire({
              icon: \'success\',
              title: \'Payment Successful! 🎉\',
              html: `
                <p style="margin:1em 0;"><b>Receipt:</b> ${receipt}</p>
                ${amt ? `<p><b>Amount:</b> ${amt}</p>` : \'\'}
                <p style="color:#4caf50;margin-top:1em;">Your data package will be sent shortly!</p>
              `,
              confirmButtonColor: \'#4caf50\'
            });
            return; // Stop polling
            
          } else if (status === \'failed\') {
            Swal.fire({
              icon: \'error\',
              title: \'Payment Failed\',
              text: \'Payment was cancelled or failed. Please try again.\',
              confirmButtonColor: \'#f44336\'
            });
            return; // Stop polling
          }
        }
        
        // Continue polling if not finished and haven\'t exceeded attempts
        if (attempt < maxAttempts) {
          setTimeout(poll, interval);
        }
        
      } catch (e) {
        console.error(\'Poll error:\', e);
        // Continue polling even on error
        if (attempt < maxAttempts) {
          setTimeout(poll, interval);
        }
      }
    };
    
    // Start first poll after initial delay
    setTimeout(poll, interval);
  }';

// Replace the old function with the new one
$before = substr($index, 0, $start_pos);
$after = substr($index, $end_pos);
$index = $before . $new_buy_function . "\n" . $after;

file_put_contents('index.php', $index);
echo "JavaScript updated with automatic polling\n";
?>
