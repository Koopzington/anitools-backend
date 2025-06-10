--
-- PostgreSQL database dump
--

-- Dumped from database version 16.2
-- Dumped by pg_dump version 16.2

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: anitools; Type: SCHEMA; Schema: -; Owner: anitools
--

CREATE SCHEMA anitools;


ALTER SCHEMA anitools OWNER TO anitools;

--
-- Name: awc_challenges_minimum_runtime_unit; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.awc_challenges_minimum_runtime_unit AS ENUM (
    'total_duration',
    'episodes',
    'volumes'
);


ALTER TYPE anitools.awc_challenges_minimum_runtime_unit OWNER TO anitools;

--
-- Name: awc_challenges_minimum_total_unit; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.awc_challenges_minimum_total_unit AS ENUM (
    'total_duration',
    'episodes',
    'volumes'
);


ALTER TYPE anitools.awc_challenges_minimum_total_unit OWNER TO anitools;

--
-- Name: mangaupdates_type; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.mangaupdates_type AS ENUM (
    'Manga',
    'Manhwa',
    'Manhua',
    'Doujinshi',
    'Novel'
);


ALTER TYPE anitools.mangaupdates_type OWNER TO anitools;

--
-- Name: media_characters_role; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.media_characters_role AS ENUM (
    'MAIN',
    'SUPPORTING',
    'BACKGROUND'
);


ALTER TYPE anitools.media_characters_role OWNER TO anitools;

--
-- Name: media_external_ids_service; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.media_external_ids_service AS ENUM (
    'MyAnimeList',
    'MangaUpdates',
    'MangaDex'
);


ALTER TYPE anitools.media_external_ids_service OWNER TO anitools;

--
-- Name: media_external_ids_sources; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.media_external_ids_sources AS ENUM (
    'AniList',
    'Animeshon',
    'AniTools',
    'MangaDex'
);


ALTER TYPE anitools.media_external_ids_sources OWNER TO anitools;

--
-- Name: media_format; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.media_format AS ENUM (
    'TV',
    'TV_SHORT',
    'MOVIE',
    'SPECIAL',
    'OVA',
    'ONA',
    'MUSIC',
    'MANGA',
    'NOVEL',
    'ONE_SHOT'
);


ALTER TYPE anitools.media_format OWNER TO anitools;

--
-- Name: media_genres_genre; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.media_genres_genre AS ENUM (
    'Action',
    'Adventure',
    'Comedy',
    'Drama',
    'Ecchi',
    'Fantasy',
    'Hentai',
    'Horror',
    'Mahou Shoujo',
    'Mecha',
    'Music',
    'Mystery',
    'Psychological',
    'Romance',
    'Sci-Fi',
    'Slice of Life',
    'Sports',
    'Supernatural',
    'Thriller'
);


ALTER TYPE anitools.media_genres_genre OWNER TO anitools;

--
-- Name: media_media_type; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.media_media_type AS ENUM (
    'ANIME',
    'MANGA'
);


ALTER TYPE anitools.media_media_type OWNER TO anitools;

--
-- Name: media_relations_relation_type; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.media_relations_relation_type AS ENUM (
    'ADAPTATION',
    'PREQUEL',
    'SEQUEL',
    'PARENT',
    'SIDE_STORY',
    'CHARACTER',
    'SUMMARY',
    'ALTERNATIVE',
    'SPIN_OFF',
    'OTHER',
    'SOURCE',
    'COMPILATION',
    'CONTAINS'
);


ALTER TYPE anitools.media_relations_relation_type OWNER TO anitools;

--
-- Name: media_season; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.media_season AS ENUM (
    'SPRING',
    'SUMMER',
    'FALL',
    'WINTER'
);


ALTER TYPE anitools.media_season OWNER TO anitools;

--
-- Name: media_source; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.media_source AS ENUM (
    'ORIGINAL',
    'MANGA',
    'LIGHT_NOVEL',
    'VISUAL_NOVEL',
    'VIDEO_GAME',
    'OTHER',
    'NOVEL',
    'DOUJINSHI',
    'ANIME',
    'WEB_NOVEL',
    'LIVE_ACTION',
    'GAME',
    'COMIC',
    'MULTIMEDIA_PROJECT',
    'PICTURE_BOOK'
);


ALTER TYPE anitools.media_source OWNER TO anitools;

--
-- Name: media_status; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.media_status AS ENUM (
    'FINISHED',
    'RELEASING',
    'NOT_YET_RELEASED',
    'CANCELLED',
    'HIATUS'
);


ALTER TYPE anitools.media_status OWNER TO anitools;

--
-- Name: user_list_activities_status; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.user_list_activities_status AS ENUM (
    'CURRENT',
    'PLANNING',
    'COMPLETED',
    'DROPPED',
    'PAUSED',
    'REPEATING'
);


ALTER TYPE anitools.user_list_activities_status OWNER TO anitools;

--
-- Name: user_lists_media_type; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.user_lists_media_type AS ENUM (
    'ANIME',
    'MANGA'
);


ALTER TYPE anitools.user_lists_media_type OWNER TO anitools;

--
-- Name: user_media_status; Type: TYPE; Schema: anitools; Owner: anitools
--

CREATE TYPE anitools.user_media_status AS ENUM (
    'CURRENT',
    'PLANNING',
    'COMPLETED',
    'DROPPED',
    'PAUSED',
    'REPEATING'
);


ALTER TYPE anitools.user_media_status OWNER TO anitools;

--
-- Name: fuzzy_date(integer, integer, integer); Type: FUNCTION; Schema: anitools; Owner: anitools
--

CREATE FUNCTION anitools.fuzzy_date(f_year integer, f_month integer, f_day integer) RETURNS date
    LANGUAGE plpgsql
    AS $$
begin
    return make_date(f_year, coalesce(f_month, 1), coalesce(f_day, 1));
end $$;


ALTER FUNCTION anitools.fuzzy_date(f_year integer, f_month integer, f_day integer) OWNER TO anitools;

--
-- Name: immutable_concat_ws(text, text[]); Type: FUNCTION; Schema: anitools; Owner: anitools
--

CREATE FUNCTION anitools.immutable_concat_ws(text, VARIADIC text[]) RETURNS text
    LANGUAGE internal IMMUTABLE PARALLEL SAFE
    AS $$text_concat_ws$$;


ALTER FUNCTION anitools.immutable_concat_ws(text, VARIADIC text[]) OWNER TO anitools;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: awc_challenges; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.awc_challenges (
    id bigint NOT NULL,
    title character varying(200) NOT NULL,
    thread_id bigint NOT NULL,
    minimum_runtime bigint,
    minimum_runtime_unit anitools.awc_challenges_minimum_runtime_unit,
    minimum_total bigint,
    minimum_total_unit anitools.awc_challenges_minimum_total_unit
);


ALTER TABLE anitools.awc_challenges OWNER TO anitools;

--
-- Name: COLUMN awc_challenges.title; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.awc_challenges.title IS 'Challenge title';


--
-- Name: COLUMN awc_challenges.minimum_runtime; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.awc_challenges.minimum_runtime IS 'Minimum runtime per requirement';


--
-- Name: COLUMN awc_challenges.minimum_runtime_unit; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.awc_challenges.minimum_runtime_unit IS 'Unit of runtime';


--
-- Name: COLUMN awc_challenges.minimum_total; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.awc_challenges.minimum_total IS 'Minimum total runtime for the challenge (e.g. 1500 minutes for monthlies)';


--
-- Name: COLUMN awc_challenges.minimum_total_unit; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.awc_challenges.minimum_total_unit IS 'Unit of total runtime for the challenge';


--
-- Name: awc_challenges_id_seq; Type: SEQUENCE; Schema: anitools; Owner: anitools
--

CREATE SEQUENCE anitools.awc_challenges_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE anitools.awc_challenges_id_seq OWNER TO anitools;

--
-- Name: awc_challenges_id_seq; Type: SEQUENCE OWNED BY; Schema: anitools; Owner: anitools
--

ALTER SEQUENCE anitools.awc_challenges_id_seq OWNED BY anitools.awc_challenges.id;


--
-- Name: awc_community_lists; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.awc_community_lists (
    media_id bigint NOT NULL,
    community_list character varying(100) NOT NULL
);


ALTER TABLE anitools.awc_community_lists OWNER TO anitools;

--
-- Name: awc_gamblers_bot_picks; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.awc_gamblers_bot_picks (
    media_id bigint NOT NULL,
    thread_id bigint NOT NULL,
    comment_id bigint NOT NULL
);


ALTER TABLE anitools.awc_gamblers_bot_picks OWNER TO anitools;

--
-- Name: awc_leaderboard; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.awc_leaderboard (
    place bigint NOT NULL,
    points smallint DEFAULT '0'::smallint NOT NULL,
    username character varying(50) NOT NULL,
    rank character varying(50) NOT NULL
);


ALTER TABLE anitools.awc_leaderboard OWNER TO anitools;

--
-- Name: awc_requirement_specific_lists; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.awc_requirement_specific_lists (
    media_id bigint NOT NULL,
    challenge_id bigint NOT NULL,
    requirement character varying(50) NOT NULL
);


ALTER TABLE anitools.awc_requirement_specific_lists OWNER TO anitools;

--
-- Name: characters; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.characters (
    id bigint NOT NULL,
    name_first character varying(200) DEFAULT NULL::character varying,
    name_middle character varying(200) DEFAULT NULL::character varying,
    name_last character varying(200) DEFAULT NULL::character varying,
    name_native character varying(200) DEFAULT NULL::character varying,
    description text,
    image character varying(200) DEFAULT NULL::character varying,
    gender character varying(50) DEFAULT NULL::character varying,
    date_of_birth_y smallint,
    date_of_birth_m smallint,
    date_of_birth_d smallint,
    blood_type character varying(50) DEFAULT NULL::character varying,
    favourites bigint DEFAULT '0'::bigint NOT NULL,
    name_alternatives jsonb,
    name_full character varying(400) GENERATED ALWAYS AS (anitools.immutable_concat_ws(' '::text, VARIADIC ARRAY[(name_first)::text, (name_middle)::text, (name_last)::text])) STORED,
    name_alternatives_spoiler jsonb,
    age character varying(200)
);


ALTER TABLE anitools.characters OWNER TO anitools;

--
-- Name: mangaupdates; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.mangaupdates (
    id bigint NOT NULL,
    last_updated timestamp without time zone,
    titles jsonb,
    description text,
    type anitools.mangaupdates_type,
    year character varying,
    cover character varying,
    genres jsonb,
    categories jsonb,
    latest_chapter integer,
    original_status text,
    licensed boolean NOT NULL,
    scanlation_completed boolean NOT NULL,
    authors jsonb,
    publishers jsonb,
    publications jsonb
);


ALTER TABLE anitools.mangaupdates OWNER TO anitools;

--
-- Name: mapping_votes; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.mapping_votes (
    media_id bigint NOT NULL,
    mangaupdates_id bigint,
    voted_by bigint NOT NULL,
    is_multivote boolean DEFAULT false NOT NULL,
    voted_on timestamp(0) without time zone NOT NULL,
    id uuid NOT NULL,
    revoked boolean DEFAULT false NOT NULL
);


ALTER TABLE anitools.mapping_votes OWNER TO anitools;

--
-- Name: COLUMN mapping_votes.media_id; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.mapping_votes.media_id IS 'AL manga ID';


--
-- Name: COLUMN mapping_votes.mangaupdates_id; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.mapping_votes.mangaupdates_id IS 'MangaUpdates ID. Can be null in case of a "None found" vote';


--
-- Name: COLUMN mapping_votes.voted_by; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.mapping_votes.voted_by IS 'AL user ID';


--
-- Name: COLUMN mapping_votes.is_multivote; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.mapping_votes.is_multivote IS 'Indicator on whether the vote is mapping the AL entry against multiple MU entries';


--
-- Name: COLUMN mapping_votes.voted_on; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.mapping_votes.voted_on IS 'Timestamp of when the vote happened';


--
-- Name: COLUMN mapping_votes.id; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.mapping_votes.id IS 'Unique ID of the vote';


--
-- Name: COLUMN mapping_votes.revoked; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.mapping_votes.revoked IS 'Indicator on whether the vote has been revoked or not';


--
-- Name: media; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.media (
    id bigint NOT NULL,
    media_type anitools.media_media_type NOT NULL,
    title_native character varying(250) DEFAULT NULL::character varying,
    title_romaji character varying(500) DEFAULT NULL::character varying,
    title_english character varying(250) DEFAULT NULL::character varying,
    season anitools.media_season,
    season_year smallint,
    format anitools.media_format,
    country_of_origin character varying(2) DEFAULT NULL::character varying NOT NULL,
    episodes integer,
    duration integer,
    source anitools.media_source,
    average_score smallint,
    mean_score smallint,
    favourites bigint DEFAULT '0'::bigint NOT NULL,
    popularity bigint NOT NULL,
    status anitools.media_status,
    is_adult smallint DEFAULT '0'::smallint NOT NULL,
    volumes smallint DEFAULT '0'::smallint NOT NULL,
    reviews smallint NOT NULL,
    start_date_y smallint,
    start_date_m smallint,
    start_date_d smallint,
    end_date_y smallint,
    end_date_m smallint,
    end_date_d smallint,
    cover_image character varying(250) DEFAULT NULL::character varying,
    status_current bigint NOT NULL,
    status_planning bigint NOT NULL,
    status_completed bigint NOT NULL,
    status_dropped bigint NOT NULL,
    status_paused bigint NOT NULL,
    genres jsonb,
    tags jsonb,
    studios jsonb,
    producers jsonb,
    external_links jsonb,
    total_duration integer GENERATED ALWAYS AS ((episodes * duration)) STORED,
    description text,
    synonyms jsonb,
    is_licensed smallint DEFAULT '0'::smallint NOT NULL
);


ALTER TABLE anitools.media OWNER TO anitools;

--
-- Name: media_characters; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.media_characters (
    media_id bigint NOT NULL,
    character_id bigint NOT NULL,
    role anitools.media_characters_role NOT NULL,
    voice_actor_id bigint,
    voice_actor_lang character varying(50) DEFAULT NULL::character varying
);


ALTER TABLE anitools.media_characters OWNER TO anitools;

--
-- Name: media_external_ids; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.media_external_ids (
    media_id bigint NOT NULL,
    service anitools.media_external_ids_service NOT NULL,
    external_id character varying(50) NOT NULL,
    source anitools.media_external_ids_sources NOT NULL
);


ALTER TABLE anitools.media_external_ids OWNER TO anitools;

--
-- Name: media_relations; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.media_relations (
    media_id bigint NOT NULL,
    related_media_id bigint NOT NULL,
    relation_type anitools.media_relations_relation_type NOT NULL
);


ALTER TABLE anitools.media_relations OWNER TO anitools;

--
-- Name: media_staff; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.media_staff (
    media_id bigint NOT NULL,
    staff_id bigint NOT NULL,
    role character varying(300) NOT NULL
);


ALTER TABLE anitools.media_staff OWNER TO anitools;

--
-- Name: media_tagcollection; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.media_tagcollection (
    category character varying NOT NULL,
    tag_name character varying NOT NULL,
    description character varying
);


ALTER TABLE anitools.media_tagcollection OWNER TO anitools;

--
-- Name: staff; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.staff (
    id bigint NOT NULL,
    name_first character varying(200) DEFAULT NULL::character varying,
    name_middle character varying(200) DEFAULT NULL::character varying,
    name_last character varying(200) DEFAULT NULL::character varying,
    name_native character varying(200) DEFAULT NULL::character varying,
    description text,
    image character varying(200) DEFAULT NULL::character varying,
    gender character varying(50) DEFAULT NULL::character varying,
    blood_type character varying(50) DEFAULT NULL::character varying,
    years_active_from bigint,
    years_active_until bigint,
    home_town character varying(100) DEFAULT NULL::character varying,
    date_of_birth_y smallint,
    date_of_birth_m smallint,
    date_of_birth_d smallint,
    date_of_death_y smallint,
    date_of_death_m smallint,
    date_of_death_d smallint,
    favourites bigint DEFAULT '0'::bigint NOT NULL,
    name_alternatives jsonb,
    name_full character varying(400) GENERATED ALWAYS AS (anitools.immutable_concat_ws(' '::text, VARIADIC ARRAY[(name_first)::text, (name_middle)::text, (name_last)::text])) STORED,
    primary_occupations jsonb
);


ALTER TABLE anitools.staff OWNER TO anitools;

--
-- Name: user; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools."user" (
    id bigint NOT NULL,
    user_name character varying(128) NOT NULL,
    mapping_votes integer DEFAULT 0 NOT NULL
);


ALTER TABLE anitools."user" OWNER TO anitools;

--
-- Name: user_list_activities; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.user_list_activities (
    user_id bigint NOT NULL,
    media_id bigint NOT NULL,
    status anitools.user_list_activities_status NOT NULL,
    created_at bigint NOT NULL,
    progress_from bigint,
    progress_to bigint
);


ALTER TABLE anitools.user_list_activities OWNER TO anitools;

--
-- Name: user_lists; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.user_lists (
    id bigint NOT NULL,
    slug character varying(100) NOT NULL,
    user_id bigint NOT NULL,
    name character varying(256) NOT NULL,
    is_custom_list boolean DEFAULT false NOT NULL,
    media_type anitools.user_lists_media_type NOT NULL,
    "position" bigint
);


ALTER TABLE anitools.user_lists OWNER TO anitools;

--
-- Name: COLUMN user_lists.slug; Type: COMMENT; Schema: anitools; Owner: anitools
--

COMMENT ON COLUMN anitools.user_lists.slug IS 'Used as value for list dropdowns to make the list IDs irrelevant for the frontend';


--
-- Name: user_lists_id_seq; Type: SEQUENCE; Schema: anitools; Owner: anitools
--

CREATE SEQUENCE anitools.user_lists_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE anitools.user_lists_id_seq OWNER TO anitools;

--
-- Name: user_lists_id_seq; Type: SEQUENCE OWNED BY; Schema: anitools; Owner: anitools
--

ALTER SEQUENCE anitools.user_lists_id_seq OWNED BY anitools.user_lists.id;


--
-- Name: user_media; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.user_media (
    user_id bigint NOT NULL,
    media_id bigint NOT NULL,
    notes text,
    status anitools.user_media_status,
    progress bigint DEFAULT '0'::bigint NOT NULL,
    progress_volumes bigint DEFAULT '0'::bigint NOT NULL,
    score numeric(5,2) DEFAULT NULL::numeric,
    repeat smallint DEFAULT '0'::smallint NOT NULL,
    started_at date,
    completed_at date,
    hidden_from_status_lists boolean DEFAULT false NOT NULL,
    created_at bigint DEFAULT 0 NOT NULL,
    updated_at bigint DEFAULT 0 NOT NULL,
    is_private boolean DEFAULT false NOT NULL
);


ALTER TABLE anitools.user_media OWNER TO anitools;

--
-- Name: user_media_list; Type: TABLE; Schema: anitools; Owner: anitools
--

CREATE TABLE anitools.user_media_list (
    user_id bigint NOT NULL,
    list_id bigint NOT NULL,
    media_id bigint NOT NULL
);


ALTER TABLE anitools.user_media_list OWNER TO anitools;

--
-- Name: awc_challenges id; Type: DEFAULT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.awc_challenges ALTER COLUMN id SET DEFAULT nextval('anitools.awc_challenges_id_seq'::regclass);


--
-- Name: user_lists id; Type: DEFAULT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.user_lists ALTER COLUMN id SET DEFAULT nextval('anitools.user_lists_id_seq'::regclass);


--
-- Name: characters characters_pk; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.characters
    ADD CONSTRAINT characters_pk PRIMARY KEY (id);


--
-- Name: awc_challenges idx_16608_primary; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.awc_challenges
    ADD CONSTRAINT idx_16608_primary PRIMARY KEY (id);


--
-- Name: awc_gamblers_bot_picks idx_16615_primary; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.awc_gamblers_bot_picks
    ADD CONSTRAINT idx_16615_primary PRIMARY KEY (media_id, thread_id, comment_id);


--
-- Name: mangaupdates mangaupdates_pk; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.mangaupdates
    ADD CONSTRAINT mangaupdates_pk PRIMARY KEY (id);


--
-- Name: media_external_ids media_external_ids_pk; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.media_external_ids
    ADD CONSTRAINT media_external_ids_pk PRIMARY KEY (media_id, service, external_id, source);


--
-- Name: media media_pk; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.media
    ADD CONSTRAINT media_pk PRIMARY KEY (id);


--
-- Name: media_staff media_staff_pk; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.media_staff
    ADD CONSTRAINT media_staff_pk PRIMARY KEY (media_id, staff_id, role);


--
-- Name: media_tagcollection media_tagcollection_pk; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.media_tagcollection
    ADD CONSTRAINT media_tagcollection_pk PRIMARY KEY (tag_name);


--
-- Name: staff staff_pk; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.staff
    ADD CONSTRAINT staff_pk PRIMARY KEY (id);


--
-- Name: user_lists user_lists_pk; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.user_lists
    ADD CONSTRAINT user_lists_pk PRIMARY KEY (id);


--
-- Name: user user_pk; Type: CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools."user"
    ADD CONSTRAINT user_pk PRIMARY KEY (id);


--
-- Name: awc_community_lists_community_list_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX awc_community_lists_community_list_idx ON anitools.awc_community_lists USING btree (community_list);


--
-- Name: awc_community_lists_media_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX awc_community_lists_media_id_fk ON anitools.awc_community_lists USING btree (media_id);


--
-- Name: awc_leaderabord_lowercase; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX awc_leaderabord_lowercase ON anitools.awc_leaderboard USING btree (lower((username)::text));


--
-- Name: awc_requirement_specific_lists_challenge_id_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX awc_requirement_specific_lists_challenge_id_idx ON anitools.awc_requirement_specific_lists USING btree (challenge_id);


--
-- Name: awc_requirement_specific_lists_media_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX awc_requirement_specific_lists_media_id_fk ON anitools.awc_requirement_specific_lists USING btree (media_id);


--
-- Name: characters_blood_type_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX characters_blood_type_idx ON anitools.characters USING btree (blood_type);


--
-- Name: characters_favourites_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX characters_favourites_idx ON anitools.characters USING btree (favourites);


--
-- Name: characters_gender_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX characters_gender_idx ON anitools.characters USING btree (gender);


--
-- Name: mangaupdates_authors_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX mangaupdates_authors_idx ON anitools.mangaupdates USING gin (authors);


--
-- Name: mangaupdates_categories_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX mangaupdates_categories_idx ON anitools.mangaupdates USING gin (categories);


--
-- Name: mangaupdates_genres_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX mangaupdates_genres_idx ON anitools.mangaupdates USING gin (genres);


--
-- Name: mangaupdates_publications_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX mangaupdates_publications_idx ON anitools.mangaupdates USING gin (publications);


--
-- Name: mangaupdates_publishers_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX mangaupdates_publishers_idx ON anitools.mangaupdates USING gin (publishers);


--
-- Name: mangaupdates_titles_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX mangaupdates_titles_idx ON anitools.mangaupdates USING gin (titles);


--
-- Name: mapping_votes_voted_by_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX mapping_votes_voted_by_idx ON anitools.mapping_votes USING btree (voted_by);


--
-- Name: media_characters_character_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_characters_character_id_fk ON anitools.media_characters USING btree (character_id);


--
-- Name: media_characters_media_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_characters_media_id_fk ON anitools.media_characters USING btree (media_id);


--
-- Name: media_characters_role_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_characters_role_idx ON anitools.media_characters USING btree (role);


--
-- Name: media_characters_voice_actor_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_characters_voice_actor_id_fk ON anitools.media_characters USING btree (voice_actor_id);


--
-- Name: media_characters_voice_actor_lang_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_characters_voice_actor_lang_idx ON anitools.media_characters USING btree (voice_actor_lang);


--
-- Name: media_countryoforigin_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_countryoforigin_idx ON anitools.media USING btree (country_of_origin);


--
-- Name: media_end_date_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_end_date_idx ON anitools.media USING btree (end_date_y, end_date_m, end_date_d);


--
-- Name: media_episodes_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_episodes_idx ON anitools.media USING btree (episodes);


--
-- Name: media_external_ids_media_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_external_ids_media_id_fk ON anitools.media_external_ids USING btree (media_id);


--
-- Name: media_external_ids_service_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_external_ids_service_idx ON anitools.media_external_ids USING btree (service);


--
-- Name: media_external_links_gin; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_external_links_gin ON anitools.media USING gin (external_links);


--
-- Name: media_format_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_format_idx ON anitools.media USING btree (format);


--
-- Name: media_genres_gin; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_genres_gin ON anitools.media USING gin (genres);


--
-- Name: media_isadult_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_isadult_idx ON anitools.media USING btree (is_adult);


--
-- Name: media_islicensed_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_islicensed_idx ON anitools.media USING btree (is_licensed);


--
-- Name: media_media_type_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_media_type_idx ON anitools.media USING btree (media_type);


--
-- Name: media_producers_gin; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_producers_gin ON anitools.media USING gin (producers);


--
-- Name: media_relations_media_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_relations_media_id_fk ON anitools.media_relations USING btree (media_id);


--
-- Name: media_relations_related_media_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_relations_related_media_id_fk ON anitools.media_relations USING btree (related_media_id);


--
-- Name: media_relations_relation_type_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_relations_relation_type_idx ON anitools.media_relations USING btree (relation_type);


--
-- Name: media_season_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_season_idx ON anitools.media USING btree (season);


--
-- Name: media_season_year_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_season_year_idx ON anitools.media USING btree (season_year);


--
-- Name: media_source_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_source_idx ON anitools.media USING btree (source);


--
-- Name: media_staff_media_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_staff_media_id_fk ON anitools.media_staff USING btree (media_id);


--
-- Name: media_staff_role_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_staff_role_idx ON anitools.media_staff USING btree (role);


--
-- Name: media_staff_staff_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_staff_staff_id_fk ON anitools.media_staff USING btree (staff_id);


--
-- Name: media_start_date_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_start_date_idx ON anitools.media USING btree (start_date_y, start_date_m, start_date_d);


--
-- Name: media_start_date_y_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_start_date_y_idx ON anitools.media USING btree (start_date_y);


--
-- Name: media_status_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_status_idx ON anitools.media USING btree (status);


--
-- Name: media_studios_gin; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_studios_gin ON anitools.media USING gin (studios);


--
-- Name: media_tagcollection_category_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_tagcollection_category_idx ON anitools.media_tagcollection USING btree (category);


--
-- Name: media_tagcollection_tag_name_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE UNIQUE INDEX media_tagcollection_tag_name_idx ON anitools.media_tagcollection USING btree (tag_name);


--
-- Name: media_tags_gin; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_tags_gin ON anitools.media USING gin (tags);


--
-- Name: media_title_english_lowered_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_title_english_lowered_idx ON anitools.media USING btree (lower((title_english)::text));


--
-- Name: media_title_native_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_title_native_idx ON anitools.media USING btree (title_native);


--
-- Name: media_title_romaji_lowered_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_title_romaji_lowered_idx ON anitools.media USING btree (lower((title_romaji)::text));


--
-- Name: media_total_duration_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_total_duration_idx ON anitools.media USING btree (total_duration);


--
-- Name: media_volumes_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX media_volumes_idx ON anitools.media USING btree (volumes);


--
-- Name: staff_names_lowered_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX staff_names_lowered_idx ON anitools.staff USING btree (lower((((((COALESCE(name_first, ''::character varying))::text || ' '::text) || (COALESCE(name_middle, ''::character varying))::text) || ' '::text) || (COALESCE(name_last, ''::character varying))::text)));


--
-- Name: user_list_activities_created_at_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_list_activities_created_at_idx ON anitools.user_list_activities USING btree (created_at);


--
-- Name: user_list_activities_media_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_list_activities_media_id_fk ON anitools.user_list_activities USING btree (media_id);


--
-- Name: user_list_activities_status_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_list_activities_status_idx ON anitools.user_list_activities USING btree (status);


--
-- Name: user_list_activities_user_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_list_activities_user_id_fk ON anitools.user_list_activities USING btree (user_id);


--
-- Name: user_lists_is_custom_list_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_lists_is_custom_list_idx ON anitools.user_lists USING btree (is_custom_list);


--
-- Name: user_lists_media_type_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_lists_media_type_idx ON anitools.user_lists USING btree (media_type);


--
-- Name: user_lists_name_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_lists_name_idx ON anitools.user_lists USING btree (name);


--
-- Name: user_lists_position_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_lists_position_idx ON anitools.user_lists USING btree ("position");


--
-- Name: user_lists_slug_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE UNIQUE INDEX user_lists_slug_idx ON anitools.user_lists USING btree (slug);


--
-- Name: user_lists_user_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_lists_user_id_fk ON anitools.user_lists USING btree (user_id);


--
-- Name: user_media_completet_at_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_media_completet_at_idx ON anitools.user_media USING btree (completed_at);


--
-- Name: user_media_list_list_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_media_list_list_id_fk ON anitools.user_media_list USING btree (list_id);


--
-- Name: user_media_list_media_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_media_list_media_id_fk ON anitools.user_media_list USING btree (media_id);


--
-- Name: user_media_list_user_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_media_list_user_id_fk ON anitools.user_media_list USING btree (user_id);


--
-- Name: user_media_media_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_media_media_id_fk ON anitools.user_media USING btree (media_id);


--
-- Name: user_media_score_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_media_score_idx ON anitools.user_media USING btree (score);


--
-- Name: user_media_started_at_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_media_started_at_idx ON anitools.user_media USING btree (started_at);


--
-- Name: user_media_status_idx; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_media_status_idx ON anitools.user_media USING btree (status);


--
-- Name: user_media_user_id_fk; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE INDEX user_media_user_id_fk ON anitools.user_media USING btree (user_id);


--
-- Name: user_name_lowered; Type: INDEX; Schema: anitools; Owner: anitools
--

CREATE UNIQUE INDEX user_name_lowered ON anitools."user" USING btree (lower((user_name)::text));


--
-- Name: awc_community_lists fk__awc_community_lists_media; Type: FK CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.awc_community_lists
    ADD CONSTRAINT fk__awc_community_lists_media FOREIGN KEY (media_id) REFERENCES anitools.media(id) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: awc_requirement_specific_lists fk_awc_requirement_specific_lists_awc_challenges; Type: FK CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.awc_requirement_specific_lists
    ADD CONSTRAINT fk_awc_requirement_specific_lists_awc_challenges FOREIGN KEY (challenge_id) REFERENCES anitools.awc_challenges(id) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: awc_requirement_specific_lists fk_awc_requirement_specific_lists_media; Type: FK CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.awc_requirement_specific_lists
    ADD CONSTRAINT fk_awc_requirement_specific_lists_media FOREIGN KEY (media_id) REFERENCES anitools.media(id) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: user_list_activities fk_user_list_activities_user; Type: FK CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.user_list_activities
    ADD CONSTRAINT fk_user_list_activities_user FOREIGN KEY (user_id) REFERENCES anitools."user"(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: user_lists fk_user_lists_user; Type: FK CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.user_lists
    ADD CONSTRAINT fk_user_lists_user FOREIGN KEY (user_id) REFERENCES anitools."user"(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: user_media_list fk_user_media_list_user; Type: FK CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.user_media_list
    ADD CONSTRAINT fk_user_media_list_user FOREIGN KEY (user_id) REFERENCES anitools."user"(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: user_media_list fk_user_media_list_user_lists; Type: FK CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.user_media_list
    ADD CONSTRAINT fk_user_media_list_user_lists FOREIGN KEY (list_id) REFERENCES anitools.user_lists(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: user_media fk_user_media_user; Type: FK CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.user_media
    ADD CONSTRAINT fk_user_media_user FOREIGN KEY (user_id) REFERENCES anitools."user"(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: media_external_ids media_external_ids_fk; Type: FK CONSTRAINT; Schema: anitools; Owner: anitools
--

ALTER TABLE ONLY anitools.media_external_ids
    ADD CONSTRAINT media_external_ids_fk FOREIGN KEY (media_id) REFERENCES anitools.media(id) ON UPDATE CASCADE ON DELETE CASCADE;

--
-- PostgreSQL database dump complete
--
