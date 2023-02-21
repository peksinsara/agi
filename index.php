#!/usr/bin/php -q
<?php

// Start the AGI session
include('/var/lib/asterisk/agi-bin/agitask/phpagi/src/phpagi.php');
$agi = new AGI();

// Get the caller ID
$callerId = $agi->parse_callerid()['username'];

// Check if the password has already been set for this caller ID
$hasPassword = isset($_SESSION['password_' . $callerId]);

// Check if the password has expired for this caller ID
if ($hasPassword && time() - $_SESSION['time_' . $callerId] > 180) {
    unset($_SESSION['password_' . $callerId]);
}

// Set the password if it hasn't been set or has expired
if (!$hasPassword || $_SESSION['expiration_' . $callerId] < time()) {
    setPassword($callerId, $agi);
}

function setPassword($callerId, $agi) {
    // Check if the password has expired before playing "is-not-set" message
    if ($_SESSION['expiration_' . $callerId] < time()) {
        $agi->stream_file('is-not-set');
    }

    $password1 = $agi->get_data('enter-password', 5000, 4)['result'];
    sleep(0.5);
    $password2 = $agi->get_data('vm-reenterpassword', 5000, 4)['result'];
    if ($password1 != $password2) {
        $agi->stream_file('wrong-try-again-smarty');
        exit;
    }
    $_SESSION['password_' . $callerId] = $password1;
    $_SESSION['time_' . $callerId] = time();
    $_SESSION['expiration_' . $callerId] = time() + 180; // set expiration time 3 minutes in the future
    // Log the password and its expiration time
    $logMsg = "Password set for caller ID $callerId: $password1, expiration time: " . date("Y-m-d H:i:s", $_SESSION['expiration_' . $callerId]);
    error_log($logMsg);
    $agi->stream_file('good');
}



// Display the options
while (true) {
    $option = $agi->get_data('available-options', 5000, 1)['result'];
    $agi->say_number($option);
    sleep(0.5);
    switch ($option) {
        case 1:
            // Prompt the user to enter the extension to call
            $extension = $agi->get_data('vm-enter-num-to-call', 5000, 6)['result'];
            if ($extension == $callerId) {
                $agi->stream_file('invalid');
                break;
            }
            // Store the phone number in the session for 3 minutes
            $_SESSION['call_' . $callerId] = $extension;
            $_SESSION['time_' . $callerId] = time();
            $_SESSION['expiration_' . $callerId] = time() + 180; // set expiration time 3 minutes in the future
            $logMsg = "Password set for caller ID $callerId: password_, expiration time: " . date("Y-m-d H:i:s", $_SESSION['expiration_' . $callerId]);
            error_log($logMsg);
            $agi->exec('Dial', "SIP/$extension");
            break;
        case 2:
            // Prompt the user to enter their old password
            $oldPassword = $agi->get_data('enter-password', 5000, 4)['result'];
            if ($oldPassword != $_SESSION['password_' . $callerId]) {
                $agi->stream_file('wrong-try-again-smarty');
                break;
            }
            // Prompt the user to enter their new password
            $newPassword1 = $agi->get_data('vm-newpassword', 5000, 4)['result'];
            sleep(0.5);
            $newPassword2 = $agi->get_data('vm-reenterpassword', 5000, 4)['result'];
            if ($newPassword1 != $newPassword2) {
                $agi->stream_file('wrong-try-again-smarty');
            } else {
                $_SESSION['password_' . $callerId] = $newPassword1;
                $_SESSION['time_' . $callerId] = time();
                $_SESSION['expiration_' . $callerId] = time() + 180; // set expiration time 3 minutes in the future
                $logMsg = "Password set for caller ID $callerId: password_, expiration time: " . date("Y-m-d H:i:s", $_SESSION['expiration_' . $callerId]);
                error_log($logMsg);
                $agi->stream_file('good');
            }
            break;

        case 3:
            // Loop back to the beginning
            continue 2;
        default:
            // Invalid option
            $agi->stream_file('invalid');
            break;
    }
}

// Check if the call has timed out
$timeStored = $_SESSION['time_' . $callerId];
if (time() - $timeStored > 240) {
    unset($_SESSION['call_' . $callerId]);
    unset($_SESSION['password_' . $callerId]);
}

// Check if there is a phone number to call
if (isset($_SESSION['call_' . $callerId])) {
    $extension = $_SESSION['call_' . $callerId];
    $agi->exec('Dial', "SIP/$extension");
}


