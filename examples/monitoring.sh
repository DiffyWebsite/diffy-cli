#!/bin/bash

# Runs monitoring on a project. Finds last set of screenshots, creates new one
# and compares them.
# You can set it on cron job so Diffy will run monitoring of your site on any schedule.

# Download and install diffy cli tool.
wget -O /usr/local/bin/diffy https://github.com/diffywebsite/diffy-cli/releases/latest/download/diffy.phar
chmod a+x /usr/local/bin/diffy

# Authenticate
#diffy auth:login $DIFFY_API_KEY

# Create new set of screenshots.
NEW_SCREENSHOT_ID=$(diffy screenshot:create $DIFFY_PROJECT_ID prod)

# Get the latest set of screenshots
LATEST_SCREENSHOT_ID=$(diffy screenshot:list --limit=1 $DIFFY_PROJECT_ID | grep \'id\' | php -r 'print(preg_replace("/[^0-9]/", "", stream_get_contents(STDIN)));')

# Compare these two
diffy diff:create $DIFFY_PROJECT_ID $NEW_SCREENSHOT_ID $LATEST_SCREENSHOT_ID
