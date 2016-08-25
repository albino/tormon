#!/usr/bin/perl -I /var/www/perl5/lib/perl5
use 5.010;
use strict;
use warnings;
use FCGI;
use Switch;
use File::Slurp;
use Template::Simple;
use Email::Valid;
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
  my $content;
  my $code;

  switch ($ENV{"REQUEST_URI"}) {
    case "/" {
      my $tt = read_file("$Bin/index.tt");
      $content = ${ $tmpl->render($tt, {version => $VERSION}) };
      $code = "\n"; # 200 OK
    }
    case "/subscribe" {
      read STDIN, my $buf, $ENV{"CONTENT_LENGTH"};
      my @pairs = split /&/, $buf;
      my %input;
      for (@pairs) {
        $_ =~ s/\+/ /g;
        $_ =~ s/%([a-fA-F0-9][a-fA-F0-9])/pack("C", hex($1))/eg;
        my ($a, $b) = split '=', $_;
        $input{$a} = $b;
      }

      if (!($input{"spam"} =~ m/London/i)) {
        $content = read_file("$Bin/e_security.tt");
        last;
      }
      if (!($input{"fp"} =~ m/^[A-F0-9]{40}$/)) {
        $content = read_file("$Bin/e_fingerprint.tt");
        last;
      }
      if (!Email::Valid->address($input{"email"})) {
        $content = read_file("$Bin/e_email.tt");
        last;
      }

      # Add the email to database
    }
    else {
      my $tt = read_file("$Bin/error.tt");
      $content = ${ $tmpl->render($tt, {err => 404}) };
      $code = "Status: 404 Not Found\n\n";
    }
  }

  my $tt = read_file("$Bin/wrapper.tt");
  my $html = $tmpl->render(
    $tt,
    {
      content => $content,
    },
  );
  print "Content-Type: text/html\n", $code, ${$html};
}
