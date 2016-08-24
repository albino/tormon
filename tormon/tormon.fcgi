#!/usr/bin/perl -I /var/www/perl5/lib/perl5
use 5.010;
use strict;
use warnings;
use FCGI;
use Switch;

my $sock = FCGI::OpenSocket(
  "/var/www/run/tormon.sock",
  5,
);

my $request = FCGI::Request(
  \*STDIN,
  \*STDOUT,
  \*STDERR,
  \%ENV,
  $sock,
  0,
);

while ($request->Accept() <= 0) {
  print "Content-Type: text/html\n\n";

  switch ($ENV{"REQUEST_URI"}) {
    case "/debug" {
      use Data::Dumper;
      print "<textarea>" . Dumper(\%ENV) . "</textarea>";
    }
    case "/" {
      print "Hello, world!";
    }
  }
}
