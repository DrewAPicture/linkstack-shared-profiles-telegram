<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link Moderation</title>
</head>
<body>
<main>
    <h1>Pending Links</h1>

    @if ($links->isEmpty())
        <p>No links are pending review.</p>
    @else
        <table>
            <caption>Links awaiting review</caption>
            <thead>
                <tr>
                    <th scope="col">Title</th>
                    <th scope="col">URL</th>
                    <th scope="col">Button</th>
                    <th scope="col">Submitted</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($links as $link)
                <tr>
                    <td>{{ $link->title }}</td>
                    <td><a href="{{ $link->link }}">{{ $link->link }}</a></td>
                    <td>{{ $link->button_name }}</td>
                    <td>{{ $link->created_at }}</td>
                    <td>
                        <form method="POST" action="{{ route('linkstack-shared-profiles.approve', $link->id) }}">
                            @csrf
                            <button type="submit">Approve</button>
                        </form>
                        <form method="POST" action="{{ route('linkstack-shared-profiles.reject', $link->id) }}">
                            @csrf
                            <button type="submit">Reject</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</main>
</body>
</html>
