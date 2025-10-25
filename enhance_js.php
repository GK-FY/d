<?php
// Read the current index.php
$content = file_get_contents('index.php');

// Find the openBuyDialog function and replace it with enhanced version
$pattern = '/function openBuyDialog\(name,price\)\{.*?(?=\n  \})/s';

$new_function = "async function openBuyDialog(name, price) {
    const pkgIndex = packages.findIndex(p => p.name === name);
    if (pkgIndex === -1) return;
    const pkg = packages[pkgIndex];

    const { value: phone } = await Swal.fire({
      title: name,
      html: `
        <div style=\"text-align:left;margin:1em 0;\">
          <p><b>Price:</b> KES ${price}</p>
          <p style=\"font-size:0.9em;color:#666;\">${pkg.description || ''}</p>
        </div>
        <input id=\"swal-phone\" class=\"swal2-input\" placeholder=\"Phone (07xxxxxxxx)\" required>
      `,
      focusConfirm: false,
      showCancelButton: true,
      confirmButtonText: 'Pay Now',
      preConfirm: () => {
        const phone = document.getElementById('swal-phone').value;
        if (!phone || phone.length < 9) {
          Swal.showValidationMessage('Please enter a valid phone number');
          return false;
        }
        return phone;
      }
    });

    if (!phone) return;

    // Show loading
    Swal.fire({
      title: 'Processing...',
      html: 'Initiating payment...',
      allowOutsideClick: false,
      didOpen: () => { Swal.showLoading(); }
    });

    try {
      const resp = await fetch(`?action=buyNow&pkg=\${pkgIndex}&phone=\${encodeURIComponent(phone)}`);
      const data = await resp.json();

      if (data.error) {
        Swal.close();
        if (data.error === 'daily_limit') {
          Swal.fire({
            icon: 'warning',
            title: '🔒 Daily Limit Reached',
            text: data.message || 'You have already purchased this package today. Please try again tomorrow.',
            confirmButtonColor: '#ff9800'
          });
        } else {
          Swal.fire('Error', data.message || data.error, 'error');
        }
        return;
      }

      if (data.ok && data.checkout) {
        // Show success message that user can close browser
        Swal.fire({
          icon: 'info',
          title: '📱 Payment Sent to Phone',
          html: `
            <p>Check your phone and enter your M-PESA PIN.</p>
            <p style=\"margin-top:1em;font-size:0.95em;color:#4caf50;\">
              ✨ <b>You can close this page!</b><br>
              We'll process your payment automatically in the background.
            </p>
          `,
          timer: 6000,
          timerProgressBar: true,
          showConfirmButton: true,
          confirmButtonText: 'OK, Got it!'
        });

        // Start automatic background polling
        startAutomaticPolling(data.checkout);
      } else {
        Swal.close();
        Swal.fire('Error', data.message || 'Payment initiation failed', 'error');
      }
    } catch (e) {
      console.error(e);
      Swal.close();
      Swal.fire('Error', 'Network error. Please try again.', 'error');
    }
  }

  // Automatic payment status polling - runs in background
  function startAutomaticPolling(checkout, maxAttempts = 20, interval = 5000) {
    let attempt = 0;
    
    const poll = async () => {
      attempt++;
      
      try {
        const r = await fetch(`?action=queryStatus&checkout=\${encodeURIComponent(checkout)}`);
        const j = await r.json();
        
        if (j && j.update) {
          const status = j.update.status;
          
          if (status === 'paid') {
            const receipt = j.update.receipt || (j.parsed && j.parsed.MpesaReceiptNumber) || 'Paid';
            const amt = (j.parsed && j.parsed.Amount) ? ('KES ' + j.parsed.Amount) : '';
            
            Swal.fire({
              icon: 'success',
              title: '🎉 Payment Successful!',
              html: `
                <p style=\"margin:1em 0;\"><b>Receipt:</b> \${receipt}</p>
                \${amt ? `<p><b>Amount:</b> \${amt}</p>` : ''}
                <p style=\"color:#4caf50;margin-top:1em;font-weight:bold;\">
                  Your data package will be sent shortly!
                </p>
              `,
              confirmButtonColor: '#4caf50',
              showClass: {
                popup: 'animate__animated animate__bounceIn'
              }
            });
            return; // Stop polling
            
          } else if (status === 'failed') {
            Swal.fire({
              icon: 'error',
              title: 'Payment Failed',
              text: 'Payment was cancelled or failed. Please try again.',
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
  ";

if (preg_match($pattern, $content, $matches)) {
    $content = preg_replace($pattern, $new_function, $content);
    file_put_contents('index.php', $content);
    echo "JavaScript enhanced successfully!\n";
} else {
    echo "Could not find openBuyDialog function - will append new version\n";
    
    // Find the script section and add the new functions before closing script tag
    $content = str_replace(
        '</script>',
        "\n  // Enhanced buy dialog with automatic polling\n  " . $new_function . "\n  }\n</script>",
        $content
    );
    file_put_contents('index.php', $content);
    echo "JavaScript functions added!\n";
}
?>
