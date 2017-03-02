<?php

# Class to create an iCal feed
# iCalendar overview at: https://en.wikipedia.org/wiki/ICalendar
# Spec at http://www.kanzaki.com/docs/ical/ and a tutorial at http://stevethomas.com.au/php/how-to-build-an-ical-calendar-with-php-and-mysql.html
# Validate at https://icalendar.org/validator.html

# Note: this file and its callers must not have a UTF-8 BOM: https://productforums.google.com/forum/#!topic/calendar/eicThu0gGdw


/*
 * Coding copyright Martin Lucas-Smith, University of Cambridge, 2003-17
 * Version 1.0.1
 * Distributed under the terms of the GNU Public Licence - http://www.gnu.org/copyleft/gpl.html
 * Download latest from: http://download.geog.cam.ac.uk/projects/ical/
 */



/* Requires an array

$events = array (
	
	id1 => array (
		'title' => string,
		'draft' => boolean,  [optional]
		'startTime' => unixtime,
		'untilTime' => untilTime,
		'location' => string,
		'description' => string,
	),
	
	id2 ...
	
	...
);

*/


# iCal class
class ical
{
	# Main function
	public function create ($events, $title, $namespace, $applicationName)
	{
		# Start an array of output lines
		$lines = array ();
		
		# Ensure the timezone (used by the date() function below) is set to serve UTC explicitly, caching the current one so that this can be reverted below; see http://stackoverflow.com/a/2387213/180733
		$defaultTimezone = date_default_timezone_get ();	// Cache current, so it can be reset at end
		date_default_timezone_set ('UTC');
		
		# Begin the document
		$lines[] = 'BEGIN:VCALENDAR';
		
		# Start with the header
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//' . $namespace . '//' . $applicationName . '//EN';
		$lines[] = 'X-WR-CALNAME:' . $this->formatterText ($title);
		$lines[] = 'X-WR-CALDESC:' . $this->formatterText ($title);	// Google Calendar ignores X-WR-CALNAME
		
		# Explicitly state the timezone as UTC
		#!# Appears that Outlook may require "DTSTART" as per RFC 2445 page 62: "The "VTIMEZONE" calendar component MUST include the "TZID" property and at least one definition of a standard or daylight component. The standard or daylight component MUST include the "DTSTART", "TZOFFSETFROM" and "TZOFFSETTO" properties.
		$lines[] = 'BEGIN:VTIMEZONE';
		$lines[] = 'TZID:UTC';
		$lines[] = 'TZNAME:UTC';
		$lines[] = 'BEGIN:STANDARD';
		$lines[] = 'TZOFFSETFROM:+0000';
		$lines[] = 'TZOFFSETTO:+0000';
		$lines[] = 'END:STANDARD';
		$lines[] = 'END:VTIMEZONE';
		
		# Add each booking
		foreach ($events as $id => $event) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'DTSTAMP:' . $this->formatterDatetime (time ());
			$lines[] = 'UID:' . $id;
			$lines[] = 'SUMMARY:' . $this->formatterText ($event['title']);
			$lines[] = 'STATUS:' . ((isSet ($event['draft']) && $event['draft']) ? 'TENTATIVE' : 'CONFIRMED');
			$lines[] = 'DTSTART:' . $this->formatterDatetime ($event['startTime']);
			$lines[] = 'DTEND:' . $this->formatterDatetime ($event['untilTime']);
			$lines[] = 'LOCATION:' . $this->formatterText ($event['location']);
			if ($event['description']) {
				$description = $this->formatterText ($event['description']);
				$lines[] = 'COMMENT:' . $description;
				$lines[] = 'DESCRIPTION:' . $description;	// Google Calendar ignores COMMENT
			}
			// $lines[] = 'GEO:'
			$lines[] = 'END:VEVENT';
		}
		
		# Close the document
		$lines[] = 'END:VCALENDAR';
		
		# Revert the server timezone
		date_default_timezone_set ($defaultTimezone);	// Revert to original
		
		# Compile the output
		$ical = implode ("\r\n", $lines);	// RFC2445 requires CRLF
		
		/* Test:
		echo $ical;
		application::dumpData ($events);
		die;
		*/
		
		# Send the HTTP headers
		header ('Content-type: text/calendar; charset=utf-8');
		header ('Content-Disposition: inline; filename="calendar.ics"');
		
		# Disable caching
		header ('Cache-Control: no-cache, must-revalidate');	// HTTP/1.1
		header ('Expires: Sat, 26 Jul 1997 05:00:00 GMT');		// Date in the past
		
		# Return the output
		return $ical;
	}
	
	
	# iCal UTC conversion; see: http://www.php.net/gmmktime#83226 and http://stackoverflow.com/questions/12234925/php-writing-a-ics-ical-file-date-formatting and http://stackoverflow.com/questions/7678830
	private function formatterDatetime ($unixtime)
	{
    	return date ('Ymd\THis\Z', $unixtime);
	}
	
	
	# iCal format helper; see section 4.1 of http://tools.ietf.org/html/rfc2445#section-4.1
	private function formatterText ($string)
	{
		# Escape the three special characters (,;\) - see http://stackoverflow.com/questions/1590368/should-a-colon-character-be-escaped-in-text-values-in-icalendar-rfc2445
		$string = preg_replace ('/\\\\/', '\\\\\\\\', $string);
		$string = preg_replace ('/([,;])/', '\\\\\\1', $string);
		
		# Retain a literal slash-n (newline) - see http://stackoverflow.com/questions/666929/encoding-newlines-in-ical-files
		$string = str_replace ('\\\\n', '\\n' . "\n", $string);	// The real newline is added on to ensure that word-wrapping doesn't then result in missing spaces
		
		# RFC2445 requires CRLF + whitespace for linebreaks
		$string = str_replace ("\r\n", "\n", $string);	// Normalise to prevent \r\r\n
		while (substr_count ($string, "\n\n")) {
			$string = str_replace ("\n\n", "\n", $string);	// Avoid blank lines
		}
		$string = str_replace ("\n", "\r\n ", wordwrap ($string, 74));
		
		# Return the amended string
		return $string;
	}
	
	
	# Instructions page with link
	public function instructionsLink ($link, $extraInstructions = false)
	{
		# Start the HTML
		$html = '';
		
		# Introduction
		$html .= "\n<p>Using this iCal feed, you can add this listing to your calendar application, such as Google Calendar or Microsoft Outlook.</p>";
		
		# Extra instructions if required
		if ($extraInstructions) {
			$html .= $extraInstructions;
		}
		
		# Compile the HTML
		$html .= "\n<div class=\"graybox\">";
		$html .= "\n<p class=\"comment\">Paste this URL into your calendar application:</p>";
		$url = htmlspecialchars ($link);
		$html .= "\n<p><a href=\"{$url}\"><img src=\"/images/icons/extras/ical.gif\" alt=\"\" style=\"width: 36px; height: 14px\" border=\"0\" /> &nbsp; <strong><tt>{$_SERVER['_SITE_URL']}{$url}</tt></strong></a></p>";
		$html .= "\n</div>";
		
		# Add Google Calendar instructions
		$html .= "\n<h3>Google Calendar instructions</h3>";
		$html .= "\n<p>To add this in Google Calendar:</p>";
		$html .= "\n<ol>";
		$html .= "\n\t<li>Copy the above link by highlighting it and pressing Control-C .</li>";
		$html .= "\n\t" . '<li>Log in to <a href="http://www.google.com/calendar" target="_blank">Google Calendar</a>.</li>';
		$html .= "\n\t<li>Once you are logged in, look on the left of the screen for 'Other calendars'. Click on the arrow box to the right of that.</li>";
		$html .= "\n\t<li>From the menu that appears, click 'Add by URL'.</li>";
		$html .= "\n\t<li>Paste in the address you copied above, and click OK.</li>";
		$html .= "\n\t<li>Wait a few seconds, and the calendar will now be listed under 'Other calendars'.</li>";
		$html .= "\n\t<li>If there isn't a colour next to the calendar, click on its name to show it. This toggles on/off this calendar in your listing, adding it any others already present.</li>";
		$html .= "\n</ol>";
		
		# Add iOS instructions
		$html .= "\n<h3>iPhone instructions</h3>";
		$html .= "\n<ol>";
		$html .= "\n\t<li>Copy the above link by holding down on the link until a menu with the option 'Copy' appears. Choose 'Copy'.</li>";
		$html .= "\n\t<li>Tap on the Settings icon from the iPhone home screen.</li>";
		$html .= "\n\t<li>Tap on 'Mail, Contacts, Calendars' from the list of device settings.</li>";
		$html .= "\n\t<li>Tap the 'Add Account' button and select 'Other' in the list of account types.</li>";
		$html .= "\n\t<li>Choose the 'Add Subscribed Calendar' option at the bottom of the screen.</li>";
		$html .= "\n\t<li>Hold down on the server field and tap 'Paste'. Tap the 'Next' button.</li>";
		$html .= "\n\t<li>Tap 'Save' once more to finish adding it to your iPhone.</li>";
		$html .= "\n</ol>";
		
		# Apple Calendar instructions
		$html .= "\n<h3>Apple Calendar instructions</h3>";
		$html .= "\n<ol>";
		$html .= "\n\t<li>You should be able to subscribe just by clicking on the link above.</li>";
		$html .= "\n</ol>";
		$html .= "\n<p>Alternatively, Apple provide <a href=\"https://support.apple.com/kb/PH11523\" target=\"_blank\">full instructions</a>:</p>";
		$html .= "\n<ol>";
		$html .= "\n\t<li>Copy the above link by highlighting it and pressing Control-C .</li>";
		$html .= "\n\t<li>In Calendar, choose File > New Calendar Subscription.</li>";
		$html .= "\n\t<li>Paste in the address you copied above, and then click Subscribe.</li>";
		$html .= "\n\t<li>Enter a name for the calendar in the Name field and choose a color from the adjacent pop-up menu.</li>";
		$html .= "\n\t<li>Click OK.</li>";
		$html .= "\n</ol>";
		
		# List of software
		$html .= "\n" . '<h3>What software supports iCal?</h3>';
		$html .= "\n<ul>";
		$html .= "\n\t<li>Google Calendar</li>";
		$html .= "\n\t<li>Microsoft Outlook</li>";
		$html .= "\n\t<li>Apple Calendar</li>";
		$html .= "\n\t<li>Thunderbird (with the Lightning extension)</li>";
		$html .= "\n\t<li>Evolution</li>";
		$html .= "\n\t<li>Yahoo! Calendar</li>";
		$html .= "\n</ul>";
		$html .= "\n<h3>More information about iCal</h3>";
		$html .= "\n". '<p>Wikipedia provides more detailed <a href="https://en.wikipedia.org/wiki/ICalendar" target="_blank">information about iCal</a>.</p>';
		
		# Return the HTML
		return $html;
	}
}

?>