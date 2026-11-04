<?php
/**
 * Copia a config/mail.local.php (no commitear) con los datos SMTP de producción
 * (los mismos que tenía includes/mailer.php en la app de tenis en el servidor).
 */
return [
    'host' => 'smtp.tu-proveedor.com',
    'port' => 587,
    'username' => 'correo@tudominio.com',
    'password' => 'contraseña_smtp',
    'encryption' => 'tls', // tls | ssl | ''
    'from_address' => 'correo@tudominio.com',
    'from_name' => 'Volea de Revés',
];
