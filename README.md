# Pantheon YML Editor
Provides utility functions to edit the pantheon.yml file for the purpose of editing workflow items.

## Usage
When creating a new Quicksilver plugin, add this package as a dependency, then add your installation instructions to `extras` in composer.json.

### Fields
|Field|Type|Description|
|:-|:-|:-|
|`wf_type` (required)|String|The [workflow](https://pantheon.io/docs/quicksilver#hooks) that is being hooked into.|
|`stage` (required)|String|The stage of the workflow being defined (`before` or `after`).|
|`script`|String|The script to execute. If not provided, it will default to a php file with the same name as the key under pantheon-quicksilver|
|`weight`|Int|Should this workflow be added to the top of the workflow when installed.|


## Example

```json
{
  "extra": {
    "pantheon-quicksilver": {
      "sanitize-db": [
        {
          "wf_type": "clone_database",
          "stage": "after",
          "script": "sanitize-db.php",
          "weight": 1
        },
        {
          "wf_type": "create_cloud_development_environment",
          "stage": "after",
          "weight": 100
        }
      ]
    }
  }
}
```

This will add the corresponding workflow to pantheon.yml like this:

```
workflows:
  clone_database:
    after:
      -
        type: webphp
        script: web/private/scripts/sanitize-db/sanitize-db.php
        description: '[pantheon-quicksilver/sanitize-db] clone_database (default)'
```

If you want to make any change to the workflow and avoid composer to reverting your changes, change "default" (in parenthesis) to "edited" (the rest of the description should remain the same).

## Removing workflows added by this plugin

If you want to remove a workflow added by this plugin and not getting it re-added in the next composer.json you should add it to the composer.json deny list like this:

```
"extra": {
    "pantheon-quicksilver": {
        "quicksilver-denylist": {
            "pantheon-quicksilver/wakeup": [
                {
                    "wf_type": "clone_database",
                    "stage": "after"
                }
            ]
        }
    }
}
```

The above lines will avoid the clone_database workflows (in after stage) for pantheon-quicksilver/wakeup to be re-added to your pantheon.yml file.
