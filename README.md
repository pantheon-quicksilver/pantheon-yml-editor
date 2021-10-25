# Pantheon YML Editor
Provides utility functions to edit the pantheon.yml file for the purpose of editing workflow items.

## Usage
When creating a new Quicksilver plugin, add this package as a dependency, then add your installation instructions to `extras` in composer.json.

### Fields
|Field|Type|Description|
|:-|:-|:-|
|`wf_type` (required)|String|The [workflow](https://pantheon.io/docs/quicksilver#hooks) that is being hooked into.|
|`stage` (required)|String|The stage of the workflow being defined (`before` or `after`).|
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
