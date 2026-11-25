[PLACEHOLDER MESSAGE: the final wording will replace this text.]

Hello {!! $recipientName !!},

An account has been created for you at {!! $company !!}.

Username: {!! $username !!}
Starting password: {!! $startingPassword !!}

Sign in here: {!! $loginUrl !!}

You will be asked to choose your own password the first time you sign in.

-- {{ config('mail.from.name') }}
