@echo off
REM ============================================================
REM XAMPP最新版（PHP 8.2+）のPHPを優先するシェルを起動する
REM ============================================================
REM 使い方: このバッチをダブルクリック → 開いたウインドウで作業
REM       例: cd demo && composer install
REM           php scripts\test_gemini.php
REM
REM XAMPPのインストール先が C:\xampp 以外なら下記を編集
REM ============================================================

set XAMPP_PHP=C:\xampp\php

if not exist "%XAMPP_PHP%\php.exe" (
    echo [ERROR] %XAMPP_PHP%\php.exe が見つかりません。
    echo XAMPPのインストール先を確認するか、このバッチの XAMPP_PHP を書き換えてください。
    pause
    exit /b 1
)

set PATH=%XAMPP_PHP%;%PATH%

echo ==========================================
echo  XAMPP 8.x PHP shell
echo ==========================================
php -v
echo.
echo ==========================================
echo  Composer
echo ==========================================
composer --version
echo.
echo  作業ディレクトリ: %CD%
echo  例: composer install
echo      php scripts\test_gemini.php
echo ==========================================

cmd /k
