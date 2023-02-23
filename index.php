#!/usr/bin/php -q
<?php
date_default_timezone_set('Europe/Sarajevo');

// Start the AGI session
ini_set('session.gc_maxlifetime', 3*60);
ini_set('session.gc_divisor', '1');
ini_set('session.gc_probability', '1');

session_start();
include('/var/lib/asterisk/agi-bin/agitask/phpagi/src/phpagi.php');
$agi = new AGI();

$callerId = $agi->parse_callerid()['username'];
$hasPassword = isset($_SESSION['password_' . $callerId]);

// Check if the password has expired for this caller ID
if ($hasPassword && time() - $_SESSION['time_' . $callerId] > 180) {
    error_log('expired password');
    unset($_SESSION['password_' . $callerId]);
}

// Set the password if it hasn't been set or has expired
if (!$hasPassword || time() - $_SESSION['time_' . $callerId] > 180) {
    setPassword($callerId, $agi);
}

// Prompt for old password and proceed with options
if ($hasPassword) {
    error_log('has password');
    $agi->stream_file('enter-password');
    $oldPassword = $agi->get_data('enter-password', 5000, 4)['result'];
    sleep(0.5);
    if ($oldPassword == $_SESSION['password_' . $callerId]) {
        // Old password was correct, proceed with options
        $agi->stream_file('good');
    } else {
        // Old password was incorrect, prompt for new password
        error_log('wrong password. setting up new one');
        $agi->stream_file('wrong-try-again-smarty');
        setPassword($callerId, $agi);
    }
} else {
    // No password set yet
    error_log('password not set');
    setPassword($callerId, $agi);
    // Check if password was set successfully
    if (!isset($_SESSION['password_' . $callerId])) {
        $agi->stream_file('is-not-set');
    }
}


function setPassword($callerId, $agi) {
    // Check if password has already been set
    if (isset($_SESSION['password_' . $callerId])) {
        // Password set before
        error_log('password set before');
        return;
    }

    $newPassword = true;
    while ($newPassword) {
        error_log('create new password');
        $password1 = $agi->get_data('enter-password', 5000, 4)['result'];
        sleep(0.5);
        $password2 = $agi->get_data('vm-reenterpassword', 5000, 4)['result'];
        if ($password1 != $password2) {
            $agi->stream_file('wrong-try-again-smarty');
            continue;
        }
        $_SESSION['password_' . $callerId] = $password1;
        $_SESSION['time_' . $callerId] = time();
        $_SESSION['expiration_' . $callerId] = time() + 180; // set expiration time 3 minutes in the future
        // Log the password and its expiration time
        $logMsg = "Password set for caller ID $callerId: $password1, expiration time: " . date("Y-m-d H:i:s", $_SESSION['expiration_' . $callerId]);
        error_log($logMsg);
        $agi->stream_file('good');
        $newPassword = false;
    }
}

// Remove all passwords after 3 minutes
if (time() - $_SESSION['time_' . $callerId] > 180) {
    unset($_SESSION['password_' . $callerId]);
}

// Restart session after 3 minutes
if (isset($_SESSION['call_' . $callerId]) && time() - $_SESSION['time_' . $callerId] > 180) {
    session_unset();
    session_destroy();
    session_start();
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
if (isset($_SESSION['call_' . $callerId]) && time() - $timeStored > 180) {
    unset($_SESSION['call_' . $callerId]);
}
if (time() - $_SESSION['time_' . $callerId] > 180) {
    unset($_SESSION['password_' . $callerId]);
}


// Check if there is a phone number to call
if (isset($_SESSION['call_' . $callerId])) {
    $extension = $_SESSION['call_' . $callerId];
    $agi->exec('Dial', "SIP/$extension");
}


