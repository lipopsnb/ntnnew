#!/bin/bash
# Thêm vào crontab: crontab -e
# 59 23 * * * /bin/bash /var/www/html/ntn_erp/cron/close_day.sh >> /var/www/html/ntn_erp/cron/close_day.log 2>&1

PHP=/usr/bin/php
SCRIPT=/var/www/html/ntn_erp/api/warehouse/close_day.php

$PHP $SCRIPT --cron --date=$(date +%Y-%m-%d)