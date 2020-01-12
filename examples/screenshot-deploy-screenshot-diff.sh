#!/bin/bash

# It is a good practice not to store keys or any other variables in the shell script itself.
# Much better to keep them as environmental variables. For example, you can configure these
# in CircleCI https://circleci.com/docs/2.0/env-vars/.

diffy auth:login $DIFFY_API_KEY

# First set of screenshots
echo "Starting taking pre-deployment screenshots..."
SCREENSHOT_ID1=`diffy screenshot:create $DIFFY_PROJECT_ID production --wait`
echo "Screenshots created $SCREENSHOT_ID1"

# Deployment.
echo "Deployment started"
sleep 10
echo "Deployment finished"

SCREENSHOT_ID2=`diffy screenshot:create $DIFFY_PROJECT_ID production`

# We can run diff while screenshots are not yet ready. We do not wait for the diff to be completed.
# Will receive a notification with results by email / slack.
DIFF_ID=`diffy diff:create $DIFFY_PROJECT_ID $SCREENSHOT_ID1 $SCREENSHOT_ID2`
echo "Diff started $DIFF_ID"
