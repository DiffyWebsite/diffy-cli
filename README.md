# DiffyCli

Command-Line tool for interacting with Diffy.

Great for building integrations for your CI/CD tools. Allows scription taking screenshots, diffs, comparing environments.

[![Travis CI](https://travis-ci.org/DiffyWebsite/diffy-cli.svg?branch=master)](https://travis-ci.org/DiffyWebsite/diffy-cli)
[![License](https://img.shields.io/badge/license-MIT-408677.svg)](LICENSE)

## Usage

### Installation

Download latest release from [https://github.com/DiffyWebsite/diffy-cli/releases](https://github.com/DiffyWebsite/diffy-cli/releases) page. You can copy file to your executables so it is available everywhere.

```shell script
chmod a+x diffy.phar
cp diffy.phar /usr/local/bin/diffy
```

### Authentication

You need to obtain a Key to interact with API. This can be done from [Profile](https://app.diffy.website/#/keys).

Once you have a key -- run
```shell script
diffy auth:login xxxxxxxxxxxx
```

This will save the key for future usages.

### Commands

```shell script
diffy screenshot:create PROJECT_ID ENVIRONMENT
```

PROJECT_ID is an ID of the project. You can get it from URL of your project.
ENVIRONMENT is one of "production", "staging", "development"

You can use `--wait` key to wait for the screenshot to be completed.

As a result you will get an ID of the screenshot.



```shell script
diffy diff:create PROJECT_ID SCREENSHOT_ID1 SCREENSHOT_ID2
```

Compare screenshots with id SCREENSHOT_ID1 and SCREENSHOT_ID2

```shell script
diffy project:compare PROJECT_ID production staging
```

or in case of custom environments

```shell script
diffy project:compare PROJECT_ID prod custom --env2Url="https://custom.example.com"
```

Allowed environments are: prod, stage, dev, custom.

### Examples

Take a look at folder with [Examples](https://github.com/DiffyWebsite/diffy-cli/tree/master/examples). This is a collection
of shell scripts that aim to give you an idea how CLI tool can be used in your CI pipelines.

## Development

### Prerequisites

List the things that are needed to install the software and how to install them. For most PHP projects, it should usually be sufficient to run:

```
composer install
```

If you wish to build the phar for this project, install the `box` phar builder via:

```
composer phar:install-tools
```

### Installing

Provide a step by step series of examples that show how to install this project.

Say what the step will be. If the phar for this project is the primary output, and not a mere development utility, then perhaps the first step will be to build the phar:

```
composer phar:build
```

It may then be sufficient to install via:

```
cp example.phar /usr/local/bin
```

End with an example of getting some data out of the system or using it for a little demo.

## Running the tests

The test suite may be run locally by way of some simple composer scripts:

| Test             | Command
| ---------------- | ---
| Run all tests    | `composer test`
| PHPUnit tests    | `composer unit`
| PHP linter       | `composer lint`
| Code style       | `composer cs`
| Fix style errors | `composer cbf`


## Deployment

Add additional notes about how to deploy this on a live system.

If your project has been set up to automatically deploy its .phar with every GitHub release, then you will be able to deploy by the following procedure:

- Edit the `VERSION` file to contain the version to release, and commit the change.
- Run `composer release`

## Built With

List significant dependencies that developers of this project will interact with.

* [Composer](https://getcomposer.org/) - Dependency Management
* [Robo](https://robo.li/) - PHP Task Runner
* [Symfony](https://symfony.com/) - PHP Framework

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on the process for submitting pull requests to us.

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the [releases](https://github.com/DiffyWebsite/diffy-cli/releases) page.

## Authors

* **Yuriy Gerasimov** - created project from template.

See also the list of [contributors](https://github.com/DiffyWebsite/diffy-cli/contributors) who participated in this project.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details

## Acknowledgments

* Hat tip to anyone who's code was used
* Inspiration
* etc
* Thanks to PurpleBooth for the [example README template](https://gist.github.com/PurpleBooth/109311bb0361f32d87a2)
