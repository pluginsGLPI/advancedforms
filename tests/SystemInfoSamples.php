<?php

/**
 * -------------------------------------------------------------------------
 * advancedforms plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2025 by the advancedforms plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/pluginsGLPI/advancedforms
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Advancedforms\Tests;

/** Samples of the text produced by the "Copy system information" button. */
final class SystemInfoSamples
{
    /** GLPI 11.0+ format: escaped content wrapped in <details><pre>. */
    public const MODERN = <<<TXT
<details><summary>GLPI information</summary><pre>
GLPI: 11.0.12 (/ =&gt; /var/www/glpi)
Installation mode: TARBALL
Current language: en_GB
Source Integrity: OK

</pre></details><details><summary>Server</summary><pre>
Operating system: Linux glpi 6.1.0-28-amd64 #1 SMP x86_64

PHP: 8.3.6 fpm-fcgi

PHP extensions: Core, date, libxml

Web server: Apache/2.4.62 (Debian) ()

Database:

   Server Software: MariaDB Server
   Server Version: 10.11.6-MariaDB-0+deb12u1
   Server SQL Mode:
</pre></details><details><summary>Plugins list</summary><pre>

datainjection        Name: Data injection                 Version: 2.15.10      State: Enabled                                  Install Method: Marketplace
fields               Name: Additional fields &amp; co        Version: 1.21.22      State: Disabled                                 Install Method: Manual
averyveryverylongpla Name: A very long plugin             Version: 1.0.0        State: To be cleaned                            Install Method: Manual
</pre></details>
TXT;

    /** GLPI 10.0 format: unescaped content, no colon after "GLPI". */
    public const LEGACY = <<<TXT
<details><summary>GLPI constants</summary><pre>GLPI 10.0.18 (/glpi => /var/www/html/glpi)
Installation mode: GIT
Source Integrity: N/A
</pre></details><details><summary>Plugins list</summary><pre>
formcreator          Name: Form Creator                   Version: 2.13.10      State: Enabled                                  Install Method: Manual
</pre></details>
TXT;

    /** Manual copy of the rendered table: no markup at all. */
    public const PLAIN = <<<TXT
GLPI: 12.0.1 (/ => /var/www/glpi)
Installation mode: DOCKER
Source Integrity: 2 files changed
PHP: 8.4.1 apache2handler
   Server Software: MySQL Community Server - GPL
   Server Version: 8.0.40
TXT;
}
