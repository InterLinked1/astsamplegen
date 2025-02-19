#!/usr/bin/php
<?php
/*
 * astsamplegen -- Generate sample configuration file(s) from Asterisk XML documentation
 *
 * (C) Copyright 2025, Naveen Albert
 */
function exception_error_handler($errno, $errstr, $errfile, $errline ) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler("exception_error_handler"); # stop the script as soon as anything goes wrong.
$defaultCustomDocLinkPrefix = "https://asterisk.phreaknet.org/#configuration-";
$defaultSampleValue = "abc123";

$longopts = array("config:", "maxdesc:", "help", "linkprefix:", "noclobber", "outdir:", "nosampval", "sampval", "verbose", "wrap:");
$options = getopt("c:d:hl:no:ps:vw:", $longopts, $restIndex);

$optConfig = (isset($options['c']) ? $options['c'] : (isset($options['config']) ? $options['config'] : NULL));
$maxDescriptionLength = (isset($options['d']) ? $options['d'] : (isset($options['maxdesc']) ? $options['maxdesc'] : 360));
$optHelp = (isset($options['h']) || isset($options['help']));
$customDocLinkPrefix = (isset($options['l']) ? $options['l'] : (isset($options['linkprefix']) ? $options['linkprefix'] : $defaultCustomDocLinkPrefix));
$noclobber = (isset($options['n']) || isset($options['noclobber']));
$outputDirectory = (isset($options['o']) ? $options['o'] : (isset($options['outdir']) ? $options['outdir'] : NULL));
$noDefaultSampleValue = (isset($options['p']) || isset($options['nosampval']));
$defaultSampleValue = (isset($options['s']) ? $options['s'] : (isset($options['sampval']) ? $options['sampval'] : $defaultSampleValue));
$maxWidth = (isset($options['w']) ? $options['w'] : (isset($options['wrap']) ? $options['wrap'] : 135));

if ($argc < 2 || $argc <= $restIndex || $optHelp) {
	printf("%s\n", "Usage: astsamplegen.php [OPTIONS] XMLDOCFILE");
	printf("Generate sample configuration file(s) from Asterisk XML documentation.\n");
	printf("  XMLDOCFILE        Path to doc/core-en_US.xml produced by Asterisk build process\n"); 
	printf("  -c, --config      Specific config file for which to generate a sample. If not specified, all config files are processed.\n");
	printf("  -d, --maxdesc     Maximum length of descriptions to include in config comments\n");
	printf("  -h, --help        Show this help\n");
	printf("  -l, --linkprefix  Custom link prefix for out-of-tree modules\n");
	printf("  -n, --noclobber   Don't overwrite existing files\n");
	printf("  -o, --outdir      Output directory in which sample files will be saved. Default is current directory.\n");
	printf("  -p, --nosampval   Don't generate sample configurations with dummy sample option values, abort if suitable value not found.\n");
	printf("  -s, --sampval     Custom dummy sample option value to use\n");
	printf("  -v, --verbose     Enable verbose log messages\n");
	printf("  -w, --wrap        Wrap config comments at this many columns. Default is 135.\n");
	printf("\n");
	printf("Example: astsamplegen.php -c confbridge.conf doc/core-en_US.xml\n");
	printf("\n");
	printf("%s\n", "(C) Naveen Albert, 2025");
	exit(2);
}
$filename = $argv[$restIndex];
if (!file_exists($filename)) {
	fprintf(STDERR, "Input file does not exist: %s\n", $filename);
	exit(2);
}

$xmlFile = file_get_contents($filename);
$xmlFile = str_replace("<literal>", "<![CDATA[<literal>", $xmlFile);
$xmlFile = str_replace("</literal>", "</literal>]]>", $xmlFile);
$xmlFile = str_replace("<replaceable>", "<![CDATA[<replaceable>", $xmlFile);
$xmlFile = str_replace("</replaceable>", "</replaceable>]]>", $xmlFile);
$xmlFile = str_replace("<filename>", "<![CDATA[<filename>", $xmlFile);
$xmlFile = str_replace("</filename>", "</filename>]]>", $xmlFile);
$xmlFile = str_replace("<emphasis>", "<![CDATA[<emphasis>", $xmlFile);
$xmlFile = str_replace("</emphasis>", "</emphasis>]]>", $xmlFile);
$xmlFile = preg_replace("/<variable>([A-Z_]+)<\/variable>/", "<![CDATA[<variable>$1</variable>]]>", $xmlFile); # variable tag used for both single words and nodes. We don't want to parse the markdown words, but do want to parse the nodes.
$xml = simplexml_load_string($xmlFile);

$tmpModuleFile = "/tmp/astsamplegen_asterisk_modules.html";
if (!file_exists($tmpModuleFile)) {
	/* Cache webpage containing modules available upstream */
	$inTreeWebpage = file_get_contents("https://docs.asterisk.org/Latest_API/API_Documentation/Module_Configuration/");
	file_put_contents($tmpModuleFile, $inTreeWebpage);
} else {
	$inTreeWebpage = file_get_contents($tmpModuleFile);
}

# Only process configInfo objects
$configsProcessed = 0;
foreach($xml->configInfo as $configInfo) {
	$configFile = $configInfo->configFile;
	$configFilename = $configFile->attributes()->name;
	$sampleFile = ($outputDirectory !== null ? $outputDirectory . "/" : "") . $configFilename . ".sample";
	if ($optConfig && $configFilename != $optConfig) { /* !=, not !== */
		if ($verbose) {
			printf("   -- Skipping %s (doesn't match filter)\n", $sampleFile);
		}
		continue;
	}
	if ($noclobber && file_exists($sampleFile)) {
		if ($verbose) {
			printf("   -- Skipping %s (already exists)\n", $sampleFile);
		}
		continue;
	}
	$moduleName = $configInfo->attributes()->name;
	$synopsis = $configInfo->synopsis;
	$configsProcessed++;
	$fp = fopen($sampleFile, "w");
	if (!$fp) {
		die("Failed to open $configFilename for writing\n");
	}
	fprintf($fp, "; %s - %s\n", $configFilename, $synopsis);

	/* Add a link to the full documentation, wherever it might reside */
	$inTree = strstr($inTreeWebpage, $moduleName);
	if ($inTree) {
		fprintf($fp, "; See https://docs.asterisk.org/Latest_API/API_Documentation/Module_Configuration/%s/ for detailed documentation\n", $moduleName);
	} else {
		fprintf($fp, "; See %s%s for detailed documentation\n", $customDocLinkPrefix, $moduleName);
	}

	/* Process each section */
	foreach($configFile->configObject as $configObject) {
		$sectionName = $configObject->attributes()->name;
		$synopsis = $configObject->synopsis;
		fprintf($fp, "\n;[%s] ; %s\n", $sectionName, $synopsis);

		/* First, figure out what the longest option name/value pair is */
		$maxOptionNameLength = 0;
		foreach($configObject->configOption as $configOption) {
			$optionName = $configOption->attributes()->name;
			$default = $configOption->attributes()->default;
			$enumValues = array();
			$description = $configOption->description;
			if ($description->enumlist) {
				foreach($description->enumlist as $enum) {
					foreach($enum as $enumEntry) {
						$k = preg_replace('/\s+/', ' ', $enumEntry->attributes()->name);
						$v = preg_replace('/\s+/', ' ', $enumEntry->para);
						$enumValues[$k] = $v;
					}
				}
			}
			$sampleValue = (!empty($default) && strlen($default) > 0 && strlen($default) <= 16 ? $default : (count($enumValues) > 0 ? array_key_first($enumValues) : $defaultSampleValue));
			if ($noDefaultSampleValue && $sampleValue === $defaultSampleValue) {
				die("$sampleFile: [$sectionName] Could not determine sample value to use for option $optionName\n");
			}
			$maxOptionNameLength = max($maxOptionNameLength, strlen($optionName) + strlen($sampleValue) + 3); /* Add space for ' = ' */
		}
		$maxCommentLineLength = $maxWidth - $maxOptionNameLength;
		$commentDelim = sprintf("\n%*s  ; ", $maxOptionNameLength, "");

		/* Process each option */
		foreach($configObject->configOption as $configOption) {
			$optionName = $configOption->attributes()->name;
			$default = $configOption->attributes()->default; /* If the setting has a default, use that as the sample value */
			$synopsis = preg_replace('/\s+/', ' ', $configOption->synopsis);
			$synopsisLength = strlen($synopsis);
			$description = $configOption->description;

			$enumValues = array();
			if ($description->enumlist) {
				foreach($description->enumlist as $enum) {
					foreach($enum as $enumEntry) {
						$k = preg_replace('/\s+/', ' ', $enumEntry->attributes()->name);
						$v = preg_replace('/\s+/', ' ', $enumEntry->para);
						$enumValues[$k] = $v;
					}
				}
			}

			$sampleValue = (!empty($default) && strlen($default) > 0 && strlen($default) <= 16 ? $default : (count($enumValues) > 0 ? array_key_first($enumValues) : $defaultSampleValue));
			$optionPair = sprintf("%s = %s", $optionName, $sampleValue);
			$synopsis = wordwrap($synopsis, $maxCommentLineLength, $commentDelim, false);
			fprintf($fp, ";%-*s ; %s", $maxOptionNameLength, $optionPair, $synopsis);

			$descriptionLength = 0;
			if ($description->para) {
				foreach($description->para as $para) {
					$descriptionLength += strlen($para);
				}
			}
			if ($description->para && $descriptionLength <= $maxDescriptionLength) {
				if (($synopsisLength + $descriptionLength) < $maxCommentLineLength) {
					/* It will fit on a single line, in fact the same line */
					fprintf($fp, ": ");
					foreach($description->para as $para) {
						$para = str_replace(array("<literal>", "</literal>"), "", $para);
						fprintf($fp, "%s\n", $para);
					}
				} else {
					fprintf($fp, "\n");
					foreach($description->para as $para) {
						$para = str_replace(array("<literal>", "</literal>"), "", $para);
						$para = preg_replace('/\s+/', ' ', $para); /* Remove any line breaks and >1 whitespace */
						$para = wordwrap($para, $maxCommentLineLength, $commentDelim, false);
						fprintf($fp, "%*s  ; %s", $maxOptionNameLength, "", trim($para));
						fprintf($fp, "\n");
					}
				}
			} else {
				fprintf($fp, "\n");
			}

			if (count($enumValues) > 0) {
				fprintf($fp, " %-*s ; Possible values are:\n", $maxOptionNameLength, "");
				foreach($enumValues as $k => $v) {
					fprintf($fp, " %-*s ; %s - %s\n", $maxOptionNameLength, "", $k, $v);
				}
			}
		}
	}

	printf(" -- Generated config %s\n", $sampleFile);
}

printf("%d config%s processed\n", $configsProcessed, $configsProcessed === 1 ? "" : "s");
?>