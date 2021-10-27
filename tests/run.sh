#!/bin/bash

set -e

# Prepare drupal-project.
cd $GITHUB_WORKSPACE/drupal-project
composer install
composer config repositories.ymleditor path pantheon-yml-editor
BRANCH_NAME=$(echo ${GITHUB_REF#refs/heads/})
# This because 1.0.7 requires this package as dev-main.
composer require pantheon-quicksilver/pantheon-yml-editor:"dev-${BRANCH_NAME} as dev-main"

# Require wakeup script.
composer require pantheon-quicksilver/wakeup
echo "Compare current pantheon.yml with the fixture"
diff -q pantheon.yml pantheon-yml-editor/tests/fixtures/1-pantheon.yml

composer remove pantheon-quicksilver/wakeup
echo "Compare current pantheon.yml with the fixture"
diff -q pantheon.yml pantheon-yml-editor/tests/fixtures/2-pantheon.yml

echo "Start with pantheon.yml with some contents and then require wakeup again"
cp pantheon-yml-editor/tests/fixtures/3-pantheon.yml pantheon.yml
composer require pantheon-quicksilver/wakeup
echo "Compare current pantheon.yml with the fixture"
diff -q pantheon.yml pantheon-yml-editor/tests/fixtures/4-pantheon.yml
