<?php
Route::get("/index/index/{say}",
    ["uses" => "\\Trendi\\Test\\Controller\\Index@index",
        "name" => "test", "middleware" => "111", "where" => ["say" => "\w+"]])->name("wang");

Route::get("/index/test", ["uses" => "\\Trendi\\Test\\Controller\\Index@test",])->name("wangtest");