# Changelog

### 0.1.56

* Added the diffy auth:register command: create an account (optionally with a coupon) and log in to it.
* Added the diffy auth:status and auth:reset-password commands.
* Added the diffy feedback:send command.
* auth:login explains when the account has no API access.
* Fixed --wait giving up after about two minutes. It now waits --max-wait seconds and exits non-zero when
  the diff fails or times out.
* DIFFYCLI_CONFIG is now the config file path when saving too, as it already was when reading.

### 1.37

* Update project from YAML file

### 0.1.28
* Added the diffy screenshot:create-baseline command
* Added the diffy screenshot:set-baseline command

### Released

* Added the diffy project:update command.
* Add possibility to create multiple projects by a JSON array using project:create command.
* Added the diffy projects:update command.

### 0.1.0 - 2020/Jan/10

* Created from template `DiffyWebsite/diffy-cli`
