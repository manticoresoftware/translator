---
title: "Install Manticore Search"
description: "Install Manticore Search: easy-to-use open-source fast database for search. Modern, fast, light-weight, outstanding full-text search capabilities"
draft: false
no_cta: true
---

## Manticore Search 6.2.12

Version 6.2.12 continues the 6.2 series and addresses issues discovered after the release of 6.2.0.

[Manticore 6.2.0 announcement](/blog/manticore-search-6-2-0/) | [6.2.12 Changelog](https://manual.manticoresearch.com/Changelog#Version-6.2.12)

### Package managers
{{< tabs >}}
  {{< tab "APT" >}}
  ### Install Manticore Search on Debian, Ubuntu or Linux Mint using APT

  Install the APT repository and the new version:
  ``` bash
  wget https://repo.manticoresearch.com/manticore-repo.noarch.deb
  sudo dpkg -i manticore-repo.noarch.deb
  sudo apt update
  sudo apt install manticore manticore-extra
  ```

  If you are upgrading from an older version, it is recommended to remove your old packages first to avoid conflicts caused by the updated package structure:
  ```bash
  sudo apt remove 'manticore*'
  ```
  It won't remove your data or configuration file.

  ### Start Manticore

  ``` bash
  sudo systemctl start manticore
  ```

  Read more about Manticore Search's APT repo in the [documentation](https://manual.manticoresearch.com/Installation/Debian_and_Ubuntu#Installing-Manticore-in-Debian-or-Ubuntu).
  {{< /tab >}}

  {{< tab "YUM" >}}
  ### Install Manticore Search on Centos, RHEL, Oracle Linux and Amazon Linux using YUM

  Install the YUM repository and the new version:
  ``` bash
  sudo yum install https://repo.manticoresearch.com/manticore-repo.noarch.rpm
  sudo yum install manticore manticore-extra
  ```

  If you are upgrading from an older version, it is recommended to remove your old packages first to avoid conflicts caused by the updated package structure:
  ```bash
  sudo yum --setopt=tsflags=noscripts remove 'manticore*'
  ```
  It won't remove your data. If you made changes to the configuration file, it will be saved to `/etc/manticoresearch/manticore.conf.rpmsave`.

  ### Start Manticore

  ``` bash
  sudo systemctl start manticore
  ```

  Read more about Manticore Search's YUM repo in the [documentation](https://manual.manticoresearch.com/Installation/RHEL_and_Centos#Installing-Manticore-packages-on-RedHat-and-CentOS).
  {{< /tab >}}

  {{< tab "Homebrew" >}}

  ### Install Manticore Search on MacOS using Homebrew

  ``` bash
  brew install manticoresoftware/tap/manticoresearch manticoresoftware/tap/manticore-extra
  ```

  ### Start Manticore

  ``` bash
  brew services start manticoresearch
  ```

  Read more about Manticore Search's Homebrew package in [documentation](https://manual.manticoresearch.com/Installation/MacOS#Via-Homebrew-package-manager).
  {{< /tab >}}

  {{< tab "Windows installer" >}}

  ### Install Manticore Search on Windows using the installer

  1. Download the [Manticore Search Installer](https://repo.manticoresearch.com/repository/manticoresearch_windows/release/x64/manticore-6.2.12-230822-dc5144d35-x64.exe) and run it. Follow the installation instructions.
  2. Choose the directory to install to.
  3. Select the components you want to install. We recommend installing all of them.
  4. Manticore comes with a preconfigured `manticore.conf` file in [RT mode](https://manual.manticoresearch.com/Read_this_first.md#Real-time-mode-vs-plain-mode). No additional configuration is required.

  Please find more details on installing and using Manticore in Windows [in the documentation](https://manual.manticoresearch.com/Installation/Windows#Installing-Manticore-in-Windows).

  {{< /tab >}}

  {{< tab "Docker" >}}
  ### One-liner to check out Manticore (non-production experimentation)

  ``` bash
  docker run -e EXTRA=1 --name manticore --rm -d manticoresearch/manticore && echo "Waiting for Manticore docker to start. Consider mapping the data_dir to make it start faster next time" && until docker logs manticore 2>&1 | grep -q "accepting connections"; do sleep 1; echo -n .; done && echo && docker exec -it manticore mysql && docker stop manticore
  ```

  {{< notice "info" >}}
  Note that upon exiting the MySQL client, the Manticore container will be stopped and removed, resulting in no saved data. For information on using Manticore in a production environment, please see below.
  {{< /notice >}}

  ### Run Manticore Search in Docker to use in production

  ``` bash
  docker run -e EXTRA=1 --name manticore -v $(pwd)/data:/var/lib/manticore -p 127.0.0.1:9306:9306 -p 127.0.0.1:9308:9308 -d manticoresearch/manticore
  ```

  This setup will enable the Manticore Columnar Library and Manticore Buddy, and run Manticore on ports 9306 for MySQL connections and 9308 for all other connections, using `./data/` as the designated data directory.

  Read more about production use [in the documentation](https://github.com/manticoresoftware/docker#production-use).
  {{< /tab >}}

{{< /tabs >}}
<hr>

### Separate packages
{{< tabs >}}
  {{< tab "Ubuntu" >}}

  {{< collapse "Ubuntu 18 Bionic" >}}
  ``` bash
  source /etc/os-release
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server-core_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-columnar-lib_2.2.4-230822-5aec342_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-backup_1.0.8-23080408-f7638f9_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-buddy_1.0.18-23080408-2befdbe_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-executor_0.7.8-23082210-810d7d3_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-tools_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-common_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-dev_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-icudata-65l.deb

  sudo apt -y update && sudo apt -y install ./*.deb
  ```
  {{< /collapse >}}
  {{< collapse "Ubuntu 20 Focal" >}}
  ``` bash
  source /etc/os-release
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server-core_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-columnar-lib_2.2.4-230822-5aec342_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-backup_1.0.8-23080408-f7638f9_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-buddy_1.0.18-23080408-2befdbe_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-executor_0.7.8-23082210-810d7d3_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-tools_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-common_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-dev_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-icudata-65l.deb

  sudo apt -y update && sudo apt -y install ./*.deb
  ```
  {{< /collapse >}}
  {{< collapse "Ubuntu 22 Jammy" >}}
  ``` bash
  source /etc/os-release
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server-core_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-columnar-lib_2.2.4-230822-5aec342_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-backup_1.0.8-23080408-f7638f9_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-buddy_1.0.18-23080408-2befdbe_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-executor_0.7.8-23082210-810d7d3_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-tools_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-common_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-dev_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-icudata-65l.deb

  sudo apt -y update && sudo apt -y install ./*.deb
  ```
  {{< /collapse >}}

  {{< /tab >}}

  {{< tab "Debian" >}}

  {{< collapse "Debian 10 Buster" >}}
  ``` bash
  source /etc/os-release
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server-core_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-columnar-lib_2.2.4-230822-5aec342_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-backup_1.0.8-23080408-f7638f9_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-buddy_1.0.18-23080408-2befdbe_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-executor_0.7.8-23082210-810d7d3_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-tools_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-common_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-dev_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-icudata-65l.deb

  sudo apt -y update && sudo apt -y install ./*.deb
  ```
  {{< /collapse >}}
  {{< collapse "Debian 11 Bullseye" >}}
  ```bash
  source /etc/os-release
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server-core_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-columnar-lib_2.2.4-230822-5aec342_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-backup_1.0.8-23080408-f7638f9_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-buddy_1.0.18-23080408-2befdbe_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-executor_0.7.8-23082210-810d7d3_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-tools_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-common_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-dev_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-icudata-65l.deb

  sudo apt -y update && sudo apt -y install ./*.deb
  ```
  {{< /collapse >}}
  {{< collapse "Debian 12 Bookworm" >}}
  ```bash
  source /etc/os-release
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server-core_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-columnar-lib_2.2.4-230822-5aec342_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-backup_1.0.8-23080408-f7638f9_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-buddy_1.0.18-23080408-2befdbe_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-executor_0.7.8-23082210-810d7d3_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-tools_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-common_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-dev_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-icudata-65l.deb

  sudo apt -y update && sudo apt -y install ./*.deb
  ```
  {{< /collapse >}}
  {{< /tab >}}

  {{< tab "Centos" >}}

  {{< collapse "Centos 7 (Oracle Linux 7, Amazon Linux 2)" >}}
  ``` bash
  arch=`arch`
  yum -y install https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-common-6.2.12_240405.76ffd700c-1.el7.centos.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-server-6.2.12_240405.76ffd700c-1.el7.centos.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-server-core-6.2.12_240405.76ffd700c-1.el7.centos.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-tools-6.2.12_240405.76ffd700c-1.el7.centos.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-6.2.12_240405.76ffd700c-1.el7.centos.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-devel-6.2.12_240405.76ffd700c-1.el7.centos.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-buddy-1.0.18_23080408.2befdbe-1.el7.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-backup-1.0.8_23080408.f7638f9-1.el7.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-executor-0.7.8_24050305.810d7d3-1.el7.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-columnar-lib-2.2.4_240503.3b451cd-1.el7.centos.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/7/${arch}/manticore-icudata.rpm
  ```
  {{< /collapse >}}
  {{< collapse "Centos 8 (Oracle Linux 8, Stream 8)" >}}
  ``` bash
  arch=`arch`
  yum -y install https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-common-6.2.12_240405.76ffd700c-1.el8.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-server-6.2.12_240405.76ffd700c-1.el8.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-server-core-6.2.12_240405.76ffd700c-1.el8.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-tools-6.2.12_240405.76ffd700c-1.el8.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-6.2.12_240405.76ffd700c-1.el8.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-devel-6.2.12_240405.76ffd700c-1.el8.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-buddy-1.0.18_23080408.2befdbe-1.el8.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-backup-1.0.8_23080408.f7638f9-1.el8.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-executor-0.7.8_24050305.810d7d3-1.el8.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-columnar-lib-2.2.4_240503.3b451cd-1.el8.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-icudata.rpm
  ```
  {{< /collapse >}}

  {{< collapse "Centos 9 (AlmaLinux 9)" >}}
  ``` bash
  arch=`arch`
  yum -y install https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-common-6.2.12_240405.76ffd700c-1.el9.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-server-6.2.12_240405.76ffd700c-1.el9.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-server-core-6.2.12_240405.76ffd700c-1.el9.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-tools-6.2.12_240405.76ffd700c-1.el9.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-6.2.12_240405.76ffd700c-1.el9.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-devel-6.2.12_240405.76ffd700c-1.el9.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-buddy-1.0.18_23080408.2befdbe-1.el9.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-backup-1.0.8_23080408.f7638f9-1.el9.noarch.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-executor-0.7.8_24050305.810d7d3-1.el9.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-columnar-lib-2.2.4_240503.3b451cd-1.el9.${arch}.rpm \
  https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-icudata.rpm
  ```
  {{< /collapse >}}

  {{< /tab >}}

  {{< tab "Mint" >}}
  {{< collapse "Linux Mint 19" >}}

  ``` bash
  VERSION_CODENAME=bionic
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server-core_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-columnar-lib_2.2.4-230822-5aec342_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-backup_1.0.8-23080408-f7638f9_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-buddy_1.0.18-23080408-2befdbe_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-executor_0.7.8-23082210-810d7d3_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-tools_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-common_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-dev_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-icudata-65l.deb

  sudo apt -y update && sudo apt -y install ./*.deb
  ```
  {{< /collapse >}}

  {{< collapse "Linux Mint 20" >}}
  ``` bash
  VERSION_CODENAME=focal
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-server-core_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-columnar-lib_2.2.4-230822-5aec342_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-backup_1.0.8-23080408-f7638f9_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-buddy_1.0.18-23080408-2befdbe_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-executor_0.7.8-23082210-810d7d3_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-tools_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-common_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore_6.2.12-230822-dc5144d35_${arch}.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-dev_6.2.12-230822-dc5144d35_all.deb \
  https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-icudata-65l.deb

  sudo apt -y update && sudo apt -y install ./*.deb
  ```
  {{< /collapse >}}

  {{< /tab >}}

{{< /tabs >}}

### Other downloads
{{< tabs >}}
  {{< tab "English, German, Russian lemmatizers" >}}
  ### English, German and Russian lemmatizers for Manticore Search
  Read more about morphology in Manticore Search [in the documentation](https://manual.manticoresearch.com/Creating_a_table/NLP_and_tokenization/Morphology).

  Unpack [en.pak.tgz](https://repo.manticoresearch.com/repository/morphology/en.pak.tgz) for English, [de.pak.tgz](https://repo.manticoresearch.com/repository/morphology/de.pak.tgz) for German, or [ru.pak.tgz](https://repo.manticoresearch.com/repository/morphology/ru.pak.tgz) for Russian into the folder specified as [lemmatizer_base](https://mnt.cr/lemmatizer_base) in your config (`/usr/share/manticore/` is the default on Linux), and restart Manticore Search.

  Here's how you can do it in your terminal:
  ``` bash
  wget -P /usr/share/manticore/ https://repo.manticoresearch.com/repository/morphology/en.pak
  wget -P /usr/share/manticore/ https://repo.manticoresearch.com/repository/morphology/de.pak
  wget -P /usr/share/manticore/ https://repo.manticoresearch.com/repository/morphology/ru.pak
  ```

  Alternatively, you can install package `manticore-language-packs` which includes the above files:

  Via APT:
  ``` bash
  apt install manticore-language-packs
  ```

  Via YUM:
  ``` bash
  yum install manticore-language-packs
  ```

  Via Homebrew on MacOS:
  ``` bash
  brew tap manticoresoftware/tap
  brew install manticoresoftware/tap/manticore-language-packs
  ```

  {{< /tab >}}

  {{< tab "Ukrainian lemmatizer" >}}
  ### Ukrainian lemmatizer for Manticore Search

  {{< collapse "Debian, Ubuntu" >}}
  https://manual.manticoresearch.com/Installation/Debian_and_Ubuntu#Ukrainian-lemmatizer
  {{< /collapse >}}
  {{< collapse "RHEL-based" >}}
  https://manual.manticoresearch.com/Installation/RHEL_and_Centos#Ukrainian-lemmatizer
  {{< /collapse >}}

  {{< /tab >}}

  {{< tab "Index converter" >}}
  ### Index converter from Sphinx/Manticore 2.x to Manticore 3.x
  Note, Manticore 4 and 5 can also read the converted indexes. Read more about migration from Sphinx 2 / Manticore 2 [in the documentation](https://manual.manticoresearch.com/Installation/Migration_from_Sphinx).

  {{< collapse "Ubuntu Bionic,Focal,Jammy | Debian Buster,Bullseye,Bookworm" >}}

  ``` bash
  sudo apt install manticore-converter
  ```
  if you use the Manticore APT repository or:
  ``` bash
  source /etc/os-release
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-converter_6.2.12-230822-dc5144d35_${arch}.deb
  sudo apt -y update && sudo apt -y install ./manticore-converter*deb
  ```
  {{< /collapse >}}

  {{< collapse "Linux Mint 19" >}}

  ``` bash
  sudo apt install manticore-converter
  ```
  if you use the Manticore APT repository or:
  ``` bash
  VERSION_CODENAME=bionic
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-converter_6.2.12-230822-dc5144d35_${arch}.deb
  sudo apt -y update && sudo apt -y install ./manticore-converter*deb
  ```
  {{< /collapse >}}

  {{< collapse "Linux Mint 20" >}}

  ``` bash
  sudo apt install manticore-converter
  ```
  if you use the Manticore APT repository or:
  ``` bash
  VERSION_CODENAME=focal
  arch=`dpkg --print-architecture`
  wget https://repo.manticoresearch.com/repository/manticoresearch_${VERSION_CODENAME}/dists/${VERSION_CODENAME}/main/binary-${arch}/manticore-converter_6.2.12-230822-dc5144d35_${arch}.deb
  sudo apt -y update && sudo apt -y install ./manticore-converter*deb
  ```
  {{< /collapse >}}

  {{< collapse "Centos 7" >}}

  ``` bash
  sudo yum install manticore-converter
  ```
  if you use the Manticore YUM repository or:
  ``` bash
  arch=`arch`
  yum -y install https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-converter-6.2.12_240405.76ffd700c-1.el9.${arch}.rpm
  ```
  {{< /collapse >}}

  {{< collapse "Centos 8, Stream 8" >}}

  ``` bash
  sudo yum install manticore-converter
  ```
  if you use the Manticore YUM repository or:
  ``` bash
  arch=`arch`
  yum -y install https://repo.manticoresearch.com/repository/manticoresearch/release/centos/8/${arch}/manticore-converter-6.2.12_240405.76ffd700c-1.el8.${arch}.rpm
  ```
  {{< /collapse >}}

  {{< collapse "Centos 9 (AlmaLinux 9)" >}}

  ``` bash
  sudo yum install manticore-converter
  ```
  if you use the Manticore YUM repository or:
  ``` bash
  arch=`arch`
  yum -y install https://repo.manticoresearch.com/repository/manticoresearch/release/centos/9/${arch}/manticore-converter-6.2.12_240405.76ffd700c-1.el9.${arch}.rpm
  ```
  {{< /collapse >}}

  {{< collapse "Windows" >}}

  {{< button "Download" "https://repo.manticoresearch.com/repository/manticoresearch_windows/release/x64/manticore-6.2.12-230822-dc5144d35-x64-converter.zip">}}
  {{< /collapse >}}

  {{< /tab >}}
{{< /tabs >}}

## Archive versions
{{< tabs >}}
  {{< tab "Before 3.5.0" >}}
  You can find packages of Manticore Search version before 3.5.0 in [Github Releases](https://github.com/manticoresoftware/manticoresearch/releases)
  {{< /tab >}}
  {{< tab "Since 3.5.0" >}}
  You can find packages of Manticore Search version since 3.5.0 in [repo.manticoresearch.com](https://repo.manticoresearch.com/)
  {{< /tab >}}
{{< /tabs >}}
