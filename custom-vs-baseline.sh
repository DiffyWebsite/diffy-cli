CUSTOM_URL="https://diffy.website"
CUSTOM_SCREENSHOT_ID=$(diffy screenshot:create 22119 custom --envUrl=${MULTIDEV_SITE_URL})
diffy diff:create 22119 ${CUSTOM_SCREENSHOT_ID} 520974
