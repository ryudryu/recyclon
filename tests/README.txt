Run security_smoke.ps1 after starting Apache and MySQL:

  powershell -ExecutionPolicy Bypass -File .\tests\security_smoke.ps1

The script checks PHP syntax and confirms that protected files and sensitive
endpoints do not respond as public HTTP 200 resources.
