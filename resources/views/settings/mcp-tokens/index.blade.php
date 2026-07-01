@extends('layouts.app')

@section('title', 'MCP Tokens')

@section('content')
    @foreach($groups as $group)
        @foreach($group['tools'] as $tool)
            <input type="checkbox" name="tools[]" value="{{ $tool['name'] }}"> {{ $tool['name'] }}
        @endforeach
    @endforeach

    @foreach($tokens as $token)
        {{ $token->label }}
    @endforeach
@endsection
