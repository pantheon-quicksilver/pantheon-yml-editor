#!/bin/bash

set -e

# Prepare drupal-project.
cd drupal-project
composer install
composer config repositories.ymleditor path pantheon-yml-editor
composer require pantheon-quicksilver/pantheon-yml-editor:"*"

# Require wakeup script.
composer require pantheon-quicksilver/wakeup
diff -q pantheon.yml pantheon-yml-editor/tests/fixtures/1-pantheon.yml

composer remove pantheon-quicksilver/wakeup
diff -q pantheon.yml pantheon-yml-editor/tests/fixtures/2-pantheon.yml
