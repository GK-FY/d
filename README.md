# Data Bundle Selling System

## Overview
A PHP-based data bundle selling platform with M-PESA STK Push integration for payments and TextSMS for notifications. Users can purchase data packages, and admins can manage packages, orders, and system settings.

## Recent Changes (October 25, 2025)

### Major Update: Enhanced Once-Per-Day System & Order Number Implementation (Latest - October 25, 2025)

**1. Improved Once-Per-Day Purchase Restriction**
- **Change**: Once a receiver phone number purchases ANY package in a day, they cannot buy any other package until tomorrow
- **Previous Behavior**: Restriction was per-package (could buy different packages on same day)
- **New Behavior**: Restriction is now account-wide for receiver phone number (once ANY package is bought, no more purchases that day)
- **Important**: 
  - The **payer** phone (M-PESA number) can be used multiple times
  - The **receiver** phone (number receiving the data package) is restricted to one purchase per day
  - This prevents abuse of promotional once-per-day packages

**2. Order Number System (Removed FY Transaction Codes)**
- **Change**: Removed the "FY" transaction code generation system, now using order numbers everywhere
- **Impact**: 
  - Admin SMS now shows order number (e.g., "FY'S-123456") instead of FY transaction codes
  - Customer SMS shows order number for reference
  - All internal systems (admin panel, payment checker) use order numbers consistently
- **Compatibility**: Works seamlessly on shared hosting with no additional dependencies

**Implementation Details:**
- Updated `index.php`: Daily check now validates receiver phone against ANY package purchase
- Updated `payment_checker.php`: Records receiver phone (not payer) when payment succeeds
- Updated `fy.php`: Admin panel now uses order numbers instead of generating FY codes
- All changes use existing file-locking mechanisms for safe shared hosting operation

### Critical Bug Fixes (October 25, 2025)

**1. Fixed Payment Initiation Error & Daily Purchase Logic**
- **Issue**: Users were getting "Network or Server error while initiating payment" and being blocked from retrying for 24 hours
- **Root Cause**: Daily purchase limit was being recorded BEFORE payment was initiated, not AFTER payment success
- **Fix Applied**:
  1. Changed daily purchase check to read-only during payment initiation
  2. Daily purchase is now recorded ONLY when payment is confirmed successful
  3. Recording happens in both:
     - Daraja callback handler (when M-PESA confirms payment)
     - Background payment checker (for automatic verification)
  4. Users can now retry failed payments without being blocked
  5. Once-per-day packages are only marked as purchased after successful payment

**2. Fixed Automatic Payment Processing**
- **Issue**: Payments were not being processed automatically; background checker was marking payments as failed too early
- **Root Cause**: 
  - Callback URL was pointing to wrong file (payment_checker.php instead of index.php)
  - Result code 4999 ("still processing") was being treated as payment failure
- **Fix Applied**:
  1. **Corrected Callback URL**: Now automatically points to `https://your-domain/index.php`
  2. **Fixed Payment Checker Logic**: Code 4999 is now treated as "pending" instead of "failed"
  3. **Continuous Checking**: System keeps checking pending payments until confirmed or timeout (5 minutes)
  4. **Automatic SMS**: Both customer and admin receive SMS when payment succeeds
  5. **Works Even if User Closes Browser**: Background checker processes payments automatically

**How Automatic Processing Works:**
1. User initiates payment → STK push sent to their phone
2. **Safaricom Callback**: When user completes payment, Safaricom sends callback to `index.php`
3. **Background Checker**: Every 3 seconds, checks status of pending payments
4. **On Success**: 
   - Order marked as paid
   - Daily purchase recorded (if once-per-day package)
   - SMS sent to customer confirming purchase
   - SMS sent to admin with payment details
5. **User Experience**: Customer receives confirmation even if they closed the website!

### New Features Added
1. **Once-Per-Day Package Option**
   - Admins can now mark packages as "once per day" purchases
   - System automatically tracks and prevents users from buying the same package multiple times in 24 hours
   - Visual indicator shows which packages have daily limits

2. **Automatic Payment Status Checking**
   - Payments are now checked automatically in the background
   - Users no longer need to click "Check Payment Status"
   - Background payment checker runs continuously via dedicated workflow

3. **Enhanced User Experience**
   - Users can close the browser after initiating payment
   - System processes payments automatically even when browser is closed
   - Success/failure notifications shown automatically when payment completes
   - Improved UI animations and styling

4. **Background Payment Processing**
   - Dedicated background service (`payment_checker.php`) monitors pending payments
   - Automatic SMS notifications sent when payment succeeds
   - Payment status updated in real-time without user intervention

## Project Structure

### Main Files
- `index.php` - User-facing frontend for purchasing packages
- `fy.php` - Admin panel for managing packages, orders, and settings
- `payment_checker.php` - Background service for automatic payment verification
- `packages.json` - Package definitions with pricing and descriptions
- `orders.json` - Order history and status tracking
- `config.json` - System configuration (M-PESA, SMS settings)
- `daily_purchases.json` - Tracks daily purchase limits per user
- `pending_payments.json` - Queue of payments being processed

### Workflows
1. **Server** - PHP development server on port 5000 (frontend)
2. **Payment Checker** - Background payment verification service

## Configuration

### Admin Access
- Admin panel: `/fy.php`
- Default credentials stored in config.json (change these!)

### M-PESA Configuration
Set up in admin panel under Settings:
- Consumer Key & Secret
- Shortcode & Passkey
- Transaction type (Paybill/Till)
- **Callback URL**: `https://your-domain.replit.dev/index.php`
  - For this Repl: `https://e544ba16-0f42-49db-a982-3d03daff6879-00-2e27k2h6334vl.worf.replit.dev/index.php`
  - **IMPORTANT**: Callback URL must point to `index.php` (where the callback handler is)
  - System auto-detects the URL if left blank
  - Register this URL in your Safaricom Daraja portal for production

### SMS Configuration
Configure TextSMS credentials:
- API Key
- Partner ID
- Shortcode
- Admin phone number for notifications

## User Preferences
- Keep existing styling and color scheme
- Maintain responsive design
- Focus on automatic, background processing for better UX
- Minimize user interaction required for transactions

## Architecture Decisions
- **JSON file storage**: Simple, portable, no database setup required
- **Background payment checker**: Ensures payments process even if user closes browser
- **Daily purchase tracking**: Prevents abuse of promotional packages
- **Automatic polling**: Reduces manual status checking, improves UX

## Development
- Language: PHP 8.2
- Frontend: HTML, CSS, JavaScript (vanilla)
- External APIs: M-PESA Daraja API, TextSMS API
- Styling: SweetAlert2 for dialogs and notifications

## Deployment Notes
- Server runs on port 5000 for frontend
- Background checker should run continuously
- Ensure write permissions for JSON files
- Set proper credentials before production use
