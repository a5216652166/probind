<?php
require 'inc/lib.inc';
include 'header.php';

$html_top = '
<H1>Detect lame delegations</H1>
';

$html_bottom = '
</DIV>
</BODY>
</HTML>
';

$entrance_prologue = '
This tool detects lame delegations on our own DNS servers. 
In order to do so, we need the hostname (or IP address) of
a DNS server <em>outside</em> of your network. The default
will usually be a sensible choice. Specifying a server
controlled through this database will accomplish exactly
nothing.<P>
<B>NB:</B> This will take a long time. Have patience.<P>
';

$entrance_body = '
<FORM action="find-lamers.php" method="post">
<INPUT type=text size=32 name=nameserver value="%s">
&nbsp;
<INPUT type=submit value="Start">
</FORM>
';

$search_prologue = '
We manage these domains, but they do not appear to be 
delegated to any of our DNS servers. Hence, some sort 
of intervention is probably appropriate. <P>
';

$search_epilogue = '
<P><HR><P>Completed.<P>
Found %s lame delegations.<P>
';

function end_domain($begin, $domainform, $serverform, $end, $noservers, &$counter)
{
	global $current_domain, $name_servers, $servers;
	$matches = 0;
	$row = "";
	$result = "";
	foreach($name_servers as $ns) {
		$row .= sprintf($serverform, $ns);
		if (isset($servers[$ns]))
			$matches++;
	}
	if (!$matches) {
		$counter++;
		$dom = sprintf($domainform, $current_domain);
		if (count($name_servers))
			$result =  $begin.$dom.$row.$end;
		else 
			$result =  $begin.$dom.$noservers.$end;
	}
	$current_domain = "";
	return $result;
}

function begin_domain($domain)
{
	global $current_domain, $name_servers;
	$name_servers = array();
	if ($current_domain) 
		end_domain();
	$current_domain = $domain;
}

function domain_name_server($nameserver)
{
	global $name_servers;
	$name_servers[] = strtolower(ltrim(trim($nameserver)));
}

function entrance_page($text = "")
{
	$db = new DB_probind;
	global $html_top, $entrance_prologue, $entrance_body, $html_bottom;
	$db->query("SELECT value FROM blackboard WHERE name = 'default_external_dns'");
	$default_resolver = $db->next_record() ? $db->Record[0] : "8.8.8.8";
	print $html_top;
	print $entrance_prologue;
	print $text;
	print sprintf($entrance_body, $default_resolver);
	print $html_bottom;
	exit;
}

function initialize_servers()
{
	$db = new DB_probind;
	global $servers;
	$db->query("SELECT hostname FROM servers");
	while ($db->next_record()) {
		$servers[$db->Record[0]] = 1;
	}
}

#
# MAIN
#
get_input();

if (!$INPUT_VARS['nameserver']) {
	entrance_page();
} else {
	$tmp = strtolower(ltrim(rtrim($INPUT_VARS['nameserver'])));
	if (ereg($DOMAIN_RE, $tmp) 
	&& ((gethostbyname($tmp) != $tmp) || valid_ip($tmp)))
		$nameserver = $tmp;
	else 
		entrance_page("Invalid hostname.<P>\n");
}

print sprintf($html_top, "#dcdcdc");
initialize_servers();

$db = new DB_probind;
$db->query("SELECT domain FROM zones WHERE (master IS NULL OR NOT master) AND domain != 'TEMPLATE' AND domain != '0.0.127.in-addr.arpa'".access()." ORDER BY domain");
$listfile = fopen("$TMP/domains", "w");
while ($db->next_record()) {
	$domain = sprintf("%s\n", $db->Record[0]);
	fwrite($listfile, $domain);
}
fclose($listfile);
print $search_prologue;
$pipe = popen("$BIN/nsrecs -h $nameserver < $TMP/domains", "r");
$lamecounter = 0;
while (!feof($pipe)) {
	$result = fgets($pipe, 1000);
	$hostnames = explode(" ", $result);
	if (strlen($hostnames[0])) {
		$zone = get_named_zone($hostnames[0]);
		$domstr = "<B><A HREF=\"zones.php?zone=".$zone['id']."\">%s</A></B> &nbsp; ";
		begin_domain($hostnames[0]);
		for ($i=1; $i<count($hostnames); $i++) {
			domain_name_server($hostnames[$i]);
		}
		print end_domain("", "$domstr is delegated to these servers:<UL>", "<LI>%s", "</UL><BR>\n", "<P>No NS records found", $lamecounter);
	}
}
pclose($pipe);
print sprintf($search_epilogue, $lamecounter);
print $html_bottom;


?>
