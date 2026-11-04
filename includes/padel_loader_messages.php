<?php

/** Mensajes dinámicos para pantallas de carga (identidad pádel). */
function padelLoaderMensajeAleatorio(): string
{
    $mensajes = [
        'Limpiando los cristales...',
        'Cambiando el overgrip...',
        'Abriendo bote de bolas nuevas...',
        'Reservando la pista central...',
        'Preparando las palas...',
        'Comprobando el cristal de la pista...',
        'Calentando en la red...',
        'Organizando el cuadro de liga...',
    ];

    return $mensajes[array_rand($mensajes)];
}
