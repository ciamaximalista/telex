<?php
function get_language_data() {
    $json_data = file_get_contents(__DIR__ . '/countries.json');
    return json_decode($json_data, true);
}