# DiffyCli

Command-Line tool for interacting with Diffy.

Great for building integrations for your CI/CD tools. Allows scription taking screenshots, diffs, comparing environments.

[![Travis CI](https://travis-ci.org/DiffyWebsite/diffy-cli.svg?branch=master)](https://travis-ci.org/DiffyWebsite/diffy-cli)
[![License](https://img.shields.io/badge/license-MIT-408677.svg)](LICENSE)

## Usage

### Manual Installation

Download latest release from [https://github.com/DiffyWebsite/diffy-cli/releases](https://github.com/DiffyWebsite/diffy-cli/releases) page. Download just `diffy.phar` file. No need for all the source code. You can copy file to your executables so it is available everywhere.

```shell script
wget -O /usr/local/bin/diffy https://github.com/diffywebsite/diffy-cli/releases/latest/download/diffy.phar
chmod a+x /usr/local/bin/diffy
```

### Installion with Composer

```shell script
composer require diffy-website/diffy-cli --with-all-dependencies
```

### Authentication

You need to obtain a Key to interact with API. This can be done from [Profile](https://app.diffy.website/#/keys).

Once you have a key -- run
```shell script
diffy auth:login xxxxxxxxxxxx
```

This will save the key for future usages.

### Commands

#### Take screenshots

```shell script
diffy screenshot:create PROJECT_ID ENVIRONMENT
```

PROJECT_ID is an ID of the project. You can get it from URL of your project.
ENVIRONMENT is one of "production", "staging", "development" (short options: "prod", "stage", "dev")

You can use `--wait` key to wait for the screenshot to be completed.

As a result you will get an ID of the screenshot.

```shell script
diffy diff:create PROJECT_ID SCREENSHOT_ID1 SCREENSHOT_ID2
```

or create diff with custom name:
```shell script
diffy diff:create PROJECT_ID SCREENSHOT_ID1 SCREENSHOT_ID2 --name="custom"
```

or create diff with custom email notification:
```shell script
diffy diff:create PROJECT_ID SCREENSHOT_ID1 SCREENSHOT_ID2 --notifications="test@icloud.com,test@gmail.com"
```

Compare screenshots with id SCREENSHOT_ID1 and SCREENSHOT_ID2

```shell script
diffy project:compare PROJECT_ID production staging
```

or compare with baseline
```shell script
diffy project:compare PROJECT_ID baseline staging
```

or in case of custom environments (also set the name for the diff to be "custom")

```shell script
diffy project:compare PROJECT_ID prod custom --env2Url="https://custom.example.com" --name="custom"
```

or in case of custom environment with basic auth credentials

```shell script
diffy project:compare PROJECT_ID prod custom --env2Url="https://custom.example.com" --env2User="user" --env2Pass="password"
```

or with existing screenshots

```shell script
diffy project:compare PROJECT_ID existing existing --screenshot1=100 --screenshot2=101
```

or with custom email notification

```shell script
diffy project:compare PROJECT_ID baseline staging --notifications="test@icloud.com,test@gmail.com"
```

Allowed environments are: prod, stage, dev, custom (long options: production, staging, development).

#### Update project(s)

If you want to update your config (For example, from CICD)

```shell script
diffy project:update PROJECT_ID ./examples/diffy_update_project.json
```
See the ./examples/diffy_update_project.json or ./examples/diffy-project-projectID-demo-test-project.yaml file for a valid config file.

For multiple projects
```shell script
diffy projects:update ./examples/diffy_update_projects.json
```
The PROJECT_ID is defined by the key inside the JSON object.

#### Create project(s)
Similar you can create a project by passing the config file.

```shell script
diffy project:create ./examples/diffy_create_project.json
```
You can create multiple projects by giving an array of projects.

#### Get project information
Get the full settings of the project

```shell script
diffy project:get PROJECT_ID
```

#### Get list of diffs

```shell script
diffy diff:list PROJECT_ID PAGE_NUMBER
```
PROJECT_ID is an ID of the project. You can get it from URL of your project.
PAGE_NUMBER is number of the page results (starts from 0)

#### Baseline

There are two commands available to work with baseline set
```shell script
diffy screenshot:create-baseline PROJECT_ID ENVIRONMENT --wait # will create set of screenshots and set them as baseline right away
diffy screenshot:set-baseline PROJECT_ID SCREENSHOT_ID # set screenshots SCREENSHOT_ID as a baseline
```

#### Create screenshots from images

```shell script
diffy screenshot:create-uploaded 342 ./examples/diffy_create_screenshot_upload.json
```

### Github integration

Main documentation page http://diffy.website/documentation/github-integration

The only difference you will need to have is to pass commit sha to compare operation:

```shell script
diffy project:compare PROJECT_ID prod custom --env2Url="https://custom.example.com" --commit-sha="29b872765b21387b7adfd67fd16b7f11942e1a56"
```

### BrowserStack integration

If you have Automate Pro plan or higher we can use Screenshot API to generate screenshots and send them to Diffy.

For that you need following steps.

Save credentials. They can be obtained at [account setting page](https://www.browserstack.com/accounts/settings).
```shell script
php diffy browserstack:save-credentials <username> <access_key>
```

Get a list of all possible browsers available to choose which ones you would like to use.
```shell script
php diffy browserstack:browsers-list
```

Run process of taking screenshots
```shell script
php diffy browserstack:screenshot PROJECT_ID http://url-of-the-site.com safari--6.0--OS__X--Lion,firefox--39.0--Windows--8 --wait=10
```

### LambdaTest integration

If you have Live plan or higher we can use Screenshot API to generate screenshots and send them to Diffy.

For that you need following steps.

First you need to save credentials. They can be obtained at [account setting page](https://accounts.lambdatest.com/detail/profile). You need to pass your Username and Access Token.
```shell script
php diffy lambdatest:save-credentials <username> <access_token>
```

Get a list of all possible browsers available to choose which ones you would like to use.
```shell script
php diffy lambdatest:browsers-list
```

Run process of taking screenshots
```shell script
php diffy lambdatest:screenshot PROJECT_ID http://url-of-the-site.com  --wait=10 windows__10--opera--75,windows__10--chrome--90
```

Once the job is completed you can see screenshots set appeared in your project.

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

