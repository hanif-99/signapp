<?php
    session_start(); 
    if(session_destroy()) {
        header('Location: https://ppik.cianjurkab.go.id'); 
    }
?>