#!/usr/bin/perl -I /var/www/perl5/lib/perl5
use 5.010;
use strict;
use warnings;
use FCGI;
use Switch;
use File::Slurp;
use Template::Simple;
use FindBin qw($Bin);

my $VERSION = "1.0";

my $tmpl = new Template::Simple (
  pre_delim  => "<%",
  post_delim => "%>",
);

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
  my $content;

  switch ($ENV{"REQUEST_URI"}) {
    case "/debug" {
      # TODO - remove this, it's a security vulnerability
      use Data::Dumper;
      $content = "<textarea>" . Dumper(\%ENV) . "</textarea>";
    }
    case "/" {
      my $tt = read_file("$Bin/index.tt");
      $content = ${ $tmpl->render($tt, {version => $VERSION}) };
    }
  }

  my $tt = read_file("$Bin/wrapper.tt");
  my $html = $tmpl->render(
    $tt,
    {
      content => $content,
    },
  );
  print ${$html};
}
