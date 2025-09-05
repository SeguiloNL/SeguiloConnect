<?php
function enc_key() { return hash('sha256', getenv('SC_KMS_KEY') ?: 'dev-key', true); }
function encrypt_blob($plain) {
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', enc_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv.$ct);
}
function decrypt_blob($blob) {
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw)<17) return null;
    $iv = substr($raw,0,16);
    $ct = substr($raw,16);
    $pt = openssl_decrypt($ct,'aes-256-cbc',enc_key(),OPENSSL_RAW_DATA,$iv);
    return $pt;
}