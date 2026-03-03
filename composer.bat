@php -d curl.cainfo="%USERPROFILE%\cacert-with-norton.pem" -d openssl.cafile="%USERPROFILE%\cacert-with-norton.pem" "%~dp0composer.phar" %*
