@echo off
cd /d %~dp0\..

echo Starting event consumers...
start cmd /k "php consumer.php OrderCreateRequestedEvent"
start cmd /k "php consumer.php OrderCreatedEvent"
start cmd /k "php consumer.php InventoryDeductedEvent"
start cmd /k "php consumer.php PaymentProcessedEvent"
echo All consumers started.
