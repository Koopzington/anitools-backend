<center>
    <img src="https://anitools.koopz.rocks/android-chrome-512x512.png" width="250"><br>
    <h1>AniTools Backend</h1>
</center>

![License](https://img.shields.io/github/license/koopzington/anitools-backend)
[![Githhub Issues](https://img.shields.io/github/issues/koopzington/anitools-backend)](https://github.com/Koopzington/anitools-backend/issues)

### This is the repository for the backend. The frontend repository is located [here](https://github.com/Koopzington/anitools).

## Why not use AniList's API directly?
AniList's API doesn't provide the filtering possibilities that one might need for finding suitable media entries for challenges of AniList's [Anime Watching Club](https://anilist.co/forum/thread/4449).
It also provides very limited filtering options for a user's own list. In addition to that AniTools provides a mapping between MangaUpdates and manga on AniList for additional convenience. The data for the mapping is sourced from [MangaDex](https://api.mangadex.org/docs/), Animeshon, [MangaBaka](https://mangabaka.dev/database) as well as through a selfwritten mapping module.

## What endpoints does the API provide and how to use them?

- `/filterValues?media_type={ANIME/MANGA}`

    Returns the possible values for all available filters which don't have a ton of values for the provided media type.

- `/staff?q={search term}`

    Search for the Staff filter on the frontend. Returns a list of IDs and names that match the provided search term.

- `/searchForFilter/{studio/muPublication/muPublisher}?q={search term}`

    Search for the Studio/Publication/Publisher filter on the frontend. Returns a list of studios/magazines/publishers that match the provided search term.

- `/userLists?user_name={AniList name}&media_type={ANIME/MANGA}`

    This endpoint returns an AniList user's lists including it's internal ID for filtering purposes as well as the amounts of total entries and how many of them are marked as completed. It also triggers the importing of the AniList user's `MediaListCollection` for the provided media type to have a fresh set of data. This import process is considerably faster compared to requesting your Anime/Manga list on AniList because aside from the `MediaList` data only the media's id is being queried since we've got the rest of the media info already in our database. Make sure to request this endpoint to let it import the user's current data before using the main endpoint.

- Lastly we've got the main endpoint served under `/` which provides you with the data you're actually looking for. The following query parameters are available:
    - `length={1-100}`: Number of results to return. Capped at 100.
    - `start={0-infinity}`: The offset of the results to return. For example 100 to get the results 101 to 200.
    - `userName={AniList name}`: Filters down the list to entries that are on the provided user's lists
    - `columns[]`: List of fields that you want the API to return. Example: `columns[0][name]=titleEng&columns[1][name]=airStatus`
    - `order[]`: Tells the backend how to sort the results. Multiple columns can be provided. Example: `order[0][column]=airStart&order[0][dir]=desc&order[1][column]=titleEng&order[1][dir]=asc`
    - `filter[]`: Tells the backend how to filter the data. This is one is a little more complicated since you can provide several levels of nesting to filter your data. While nesting conditions you can decide between `and` or `or` to tell the API wether those conditions all have to be met or just one of them. Example: `filter[and][mediaType]=ANIME&filter[and][episodesMin]=1&filter[and][episodesMax]=12&filter[and][showAdult]=false`. This translates into the following:
        - All of the following conditions have to match:
            - Media type must be ANIME
            - Media must at least have 1 episode
            - Media must have 12 episodes at most
            - Media must not be flagged as "adult"

## How to set this up?
### Requirements
- Bash
- Docker
- Docker Compose plugin

```
$ git clone git@github.com:Koopzington/anitools-backend.git
$ cd anitools-backend
$ ./generate-config.sh
$ docker compose up -d
```

the `generate-config.sh` creates an .env files with the environment variables that are being used inside the containers, namely `MYSQL_USER`, `MYSQL_DATABASE`, `MYSQL_PASSWORD` and `MYSQL_ROOT_PASSWORD`. Those are the database credentials that will be used for the creation of your db and the first three will also get told to the PHP containers so they know how to connect to the database. Keep in mind that changing these variables after the creation of your database won't result in the database automatically updating it's credentials. You'll either have to do that manually or start with a completely new database by deleting the `postgres` folder (after shutting everything down with a `docker compose down` first).

The scraping and importing of data is happening via command line:
```
$ docker compose exec cron php cli.php app:scrape anilist all-media-data
$ docker compose exec cron php cli.php app:import anilist
```
### Cronjobs
The scraping tasks are setup to automatically run on a weekly basis while the AWC leaderboard gets updated every 15 minutes.

Since the AniList API has a 90 requests per minute ratelimit the scraping process does take quite a while to finish.

