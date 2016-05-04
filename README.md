# Terminus Sites Status

Terminus plugin to report the status of all available Pantheon sites

## Installation:

Refer to the [Terminus Wiki](https://github.com/pantheon-systems/terminus/wiki/Plugins).

## Usage:
```
$ terminus sites status [--env=<env>] [--team] [--owner] [--org=<id>] [--name=<regex>] [--cached]
```
The associative arguments are all optional and the same filtering rules as the `terminus sites list` command apply.

The output will be displayed in a table format.  The `Condition` column displays whether there are pending filesystem changes.

If the `Condition` column displays `dirty`, it means the code is out of sync with the repository.

## Examples:
```
$ terminus sites status
```
Report the status of all available sites
```
$ terminus sites status --env=dev
```
Report the status of the dev environment only of all available sites
```
