<!-- References:
https://www.twilio.com/blog/get-started-docker-laravel
https://laravel-for-newbie.kejyun.com/en/advanced/scheduling/docker/
https://github.com/mohammadain/laravel-docker-cron/blob/master/Dockerfile 
https://github.com/inttter/md-badges?tab=readme-ov-file#-programming-languages-->

<!-- Improved compatibility of back to top link: See: https://github.com/othneildrew/Best-README-Template/pull/73 -->

<a name="readme-top"></a>

<!-- PROJECT LOGO -->
<br />
<div align="center">
  <a href="https://github.com/rconfig/rconfig">
            <img src="https://www.rconfig.com/images/brand/logos/gradient_msp_logo.svg" alt="rConfig Logo" height="40" />
  </a>

  <h3 align="center">rConfig Vector Server Package</h3>

  <p align="center">
Welcome to the rConfig Vector Server Package repository! This package is an add-on to rConfig V7 Professional for licensed rConfig MSP/Enterprise Edition customers. It is designed exclusively for those customers and is open-sourced for ease of access. Contributions are not permitted, and notes below are for internal developer purposes only.
    <br />
    <br />
    <a href="https://v6docs.rconfig.com"><strong>Explore the docs »</strong></a>
    <br />
    <br />
    <a href="#overview">Overview</a>
    ·
    <a href="#contributing">Contributing</a>
    ·
    <a href="#license">License</a>
    ·
    <a href="#support">Support</a>
  </p>

[![Tests](https://github.com/eliashaeussler/typo3-badges/actions/workflows/tests.yaml/badge.svg)](https://github.com/eliashaeussler/typo3-badges/actions/workflows/tests.yaml)
[![Go](https://img.shields.io/badge/Go-%2300ADD8.svg?&logo=go&logoColor=white)](#)

</div>

## Overview

The rConfig Vector Server Package is an integral part of the rConfig V7 Professional ecosystem, specifically tailored for licensed rConfig MSP/Enterprise Edition customers. This package provides advanced server-side functionalities and is open-sourced for accessibility while remaining strictly controlled for internal development. 

Key highlights:

- Exclusively for rConfig MSP/Enterprise Edition customers.
- Designed to integrate seamlessly with the rConfig build process.
- Open-sourced for transparency and ease of deployment.
- Contributions are not permitted to maintain the integrity and security of the package.

## Testing

All tests for the Vector Server Package are conducted as part of the rConfig V7 test suite with this package loaded.

## Tagging and Pushing

To ensure proper version management and integration with Composer and GitHub, follow these steps:

1. **Clear rConfig Cache**
   ```bash
   php artisan rconfig:clear-all
   ```

2. **Update the Composer Version**
   - Open the `composer.json` file in the `rconfig/vector-server` repository.
   - Update the `version` field to match the desired tag (e.g., `0.0.7`).
     Example:
     ```json
     "version": "0.0.7",
     ```
   - Note: If managing versions via Git tags, it’s often better to omit the `version` field since Composer can infer the version from the tag.

3. **Commit and Push Changes**
   ```bash
   git commit -am "Update composer version to 0.0.7"
   git push origin main
   ```

4. **Tag the Correct Version**
   - If the tag is incorrect or points to the wrong commit, fix it as follows:
     ```bash
     git tag -d 0.0.7 # Delete the incorrect tag locally
     git push origin :refs/tags/0.0.7 # Delete the incorrect tag remotely

     git tag -a 0.0.7 -m "Version 0.0.7" # Create the correct tag
     git push origin 0.0.7 # Push the correct tag to the remote
     ```

5. **Clear Composer Cache**
   ```bash
   composer clear-cache
   composer update
   ```

6. **Create a GitHub Release**
   - Create a new release on GitHub with the correct tag and release notes.

7. **Update Packagist**
   - Ensure the package is updated in [Repman](https://app.repman.io/login) or Packagist as needed.

## Contributing

Contributions are not permitted for this package. For internal development notes, please refer to the internal developer WIKI.

## License

This project is licensed as part of the rConfig MSP and Enterprise License. See the LICENSE file for details.

## Support

For issues, questions, or feature requests, please use the [support.rconfig.com](https://support.rconfig.com) page. For enterprise support, contact us via [support@rconfig.com](mailto:support@rconfig.com).

## Acknowledgments

Thanks to all contributors and users who make rConfig a reliable solution for network configuration management.

---

*This README is a living document and may be updated as the project evolves.*

