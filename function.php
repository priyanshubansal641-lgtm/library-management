<?php

function getSystemRole($erpUserId) {
    
    if ($erpUserId == 'kiosk') {
        return 'kiosk';
    }
    
    if ($erpUserId == 'admin' || $erpUserId == 'faculty') {
        return 'admin';
    }
    
    if ($erpUserId == 'student') {
        return 'student';
    }
    
    return null;
}

function getRoleFromERP($erpUserId) {
    $role = getSystemRole($erpUserId);
    
    if ($role == null) {
        return array('error' => 'User not found or no role assigned');
    }
    
    return array('userId' => $erpUserId, 'role' => $role);
}

function redirectByRole($role) {
    
    if ($role == 'admin' || $role == 'faculty') {
        header('Location: admin-portal.html');
        exit;
    }
    
    if ($role == 'kiosk') {
        header('Location: library-screen.html');
        exit;
    }
    
    if ($role == 'student') {
        header('Location: student-view.html');
        exit;
    }
    
    header('Location: student-view.html');
    exit;
}
