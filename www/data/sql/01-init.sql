-- DROP SCHEMA anitools;

CREATE SCHEMA anitools AUTHORIZATION anitools;

-- DROP TYPE anitools."awc_challenges_minimum_runtime_unit";

CREATE TYPE anitools."awc_challenges_minimum_runtime_unit" AS ENUM (
	'total_duration',
	'episodes',
	'volumes');

-- DROP TYPE anitools."awc_challenges_minimum_total_unit";

CREATE TYPE anitools."awc_challenges_minimum_total_unit" AS ENUM (
	'total_duration',
	'episodes',
	'volumes');

-- DROP TYPE anitools."mangaupdates_type";

CREATE TYPE anitools."mangaupdates_type" AS ENUM (
	'Manga',
	'Manhwa',
	'Manhua',
	'Doujinshi',
	'Novel');

-- DROP TYPE anitools."media_characters_role";

CREATE TYPE anitools."media_characters_role" AS ENUM (
	'MAIN',
	'SUPPORTING',
	'BACKGROUND');

-- DROP TYPE anitools."media_external_ids_service";

CREATE TYPE anitools."media_external_ids_service" AS ENUM (
	'MyAnimeList',
	'MangaUpdates',
	'MangaDex');

-- DROP TYPE anitools."media_external_ids_sources";

CREATE TYPE anitools."media_external_ids_sources" AS ENUM (
	'AniList',
	'Animeshon',
	'AniTools',
	'MangaDex');

-- DROP TYPE anitools."media_format";

CREATE TYPE anitools."media_format" AS ENUM (
	'TV',
	'TV_SHORT',
	'MOVIE',
	'SPECIAL',
	'OVA',
	'ONA',
	'MUSIC',
	'MANGA',
	'NOVEL',
	'ONE_SHOT');

-- DROP TYPE anitools."media_genres_genre";

CREATE TYPE anitools."media_genres_genre" AS ENUM (
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
	'Thriller');

-- DROP TYPE anitools."media_media_type";

CREATE TYPE anitools."media_media_type" AS ENUM (
	'ANIME',
	'MANGA');

-- DROP TYPE anitools."media_relations_relation_type";

CREATE TYPE anitools."media_relations_relation_type" AS ENUM (
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
	'CONTAINS');

-- DROP TYPE anitools."media_season";

CREATE TYPE anitools."media_season" AS ENUM (
	'SPRING',
	'SUMMER',
	'FALL',
	'WINTER');

-- DROP TYPE anitools."media_source";

CREATE TYPE anitools."media_source" AS ENUM (
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
	'PICTURE_BOOK');

-- DROP TYPE anitools."media_status";

CREATE TYPE anitools."media_status" AS ENUM (
	'FINISHED',
	'RELEASING',
	'NOT_YET_RELEASED',
	'CANCELLED',
	'HIATUS');

-- DROP TYPE anitools."user_list_activities_status";

CREATE TYPE anitools."user_list_activities_status" AS ENUM (
	'CURRENT',
	'PLANNING',
	'COMPLETED',
	'DROPPED',
	'PAUSED',
	'REPEATING');

-- DROP TYPE anitools."user_lists_media_type";

CREATE TYPE anitools."user_lists_media_type" AS ENUM (
	'ANIME',
	'MANGA');

-- DROP TYPE anitools."user_media_status";

CREATE TYPE anitools."user_media_status" AS ENUM (
	'CURRENT',
	'PLANNING',
	'COMPLETED',
	'DROPPED',
	'PAUSED',
	'REPEATING');

-- DROP SEQUENCE anitools.awc_challenges_id_seq;

CREATE SEQUENCE anitools.awc_challenges_id_seq
	INCREMENT BY 1
	MINVALUE 1
	MAXVALUE 9223372036854775807
	START 1
	CACHE 1
	NO CYCLE;
-- DROP SEQUENCE anitools.user_lists_id_seq;

CREATE SEQUENCE anitools.user_lists_id_seq
	INCREMENT BY 1
	MINVALUE 1
	MAXVALUE 9223372036854775807
	START 1
	CACHE 1
	NO CYCLE;-- anitools.awc_challenges definition

-- Drop table

-- DROP TABLE anitools.awc_challenges;

CREATE TABLE anitools.awc_challenges (
	id bigserial NOT NULL,
	title varchar(200) NOT NULL, -- Challenge title
	thread_id int8 NOT NULL,
	minimum_runtime int8 NULL, -- Minimum runtime per requirement
	minimum_runtime_unit anitools."awc_challenges_minimum_runtime_unit" NULL, -- Unit of runtime
	minimum_total int8 NULL, -- Minimum total runtime for the challenge (e.g. 1500 minutes for monthlies)
	minimum_total_unit anitools."awc_challenges_minimum_total_unit" NULL, -- Unit of total runtime for the challenge
	CONSTRAINT idx_16608_primary PRIMARY KEY (id)
);

-- Column comments

COMMENT ON COLUMN anitools.awc_challenges.title IS 'Challenge title';
COMMENT ON COLUMN anitools.awc_challenges.minimum_runtime IS 'Minimum runtime per requirement';
COMMENT ON COLUMN anitools.awc_challenges.minimum_runtime_unit IS 'Unit of runtime';
COMMENT ON COLUMN anitools.awc_challenges.minimum_total IS 'Minimum total runtime for the challenge (e.g. 1500 minutes for monthlies)';
COMMENT ON COLUMN anitools.awc_challenges.minimum_total_unit IS 'Unit of total runtime for the challenge';


-- anitools.awc_gamblers_bot_picks definition

-- Drop table

-- DROP TABLE anitools.awc_gamblers_bot_picks;

CREATE TABLE anitools.awc_gamblers_bot_picks (
	media_id int8 NOT NULL,
	thread_id int8 NOT NULL,
	comment_id int8 NOT NULL,
	CONSTRAINT idx_16615_primary PRIMARY KEY (media_id, thread_id, comment_id)
);


-- anitools.awc_leaderboard definition

-- Drop table

-- DROP TABLE anitools.awc_leaderboard;

CREATE TABLE anitools.awc_leaderboard (
	place int8 NOT NULL,
	points int2 NOT NULL DEFAULT '0'::smallint,
	username varchar(50) NOT NULL,
	"rank" varchar(50) NOT NULL
);
CREATE INDEX awc_leaderabord_lowercase ON anitools.awc_leaderboard USING btree (lower((username)::text));


-- anitools."characters" definition

-- Drop table

-- DROP TABLE anitools."characters";

CREATE TABLE anitools."characters" (
	id int8 NOT NULL,
	name_first varchar(200) NULL DEFAULT NULL::character varying,
	name_middle varchar(200) NULL DEFAULT NULL::character varying,
	name_last varchar(200) NULL DEFAULT NULL::character varying,
	name_native varchar(200) NULL DEFAULT NULL::character varying,
	description text NULL,
	image varchar(200) NULL DEFAULT NULL::character varying,
	gender varchar(50) NULL DEFAULT NULL::character varying,
	date_of_birth_y int2 NULL,
	date_of_birth_m int2 NULL,
	date_of_birth_d int2 NULL,
	blood_type varchar(50) NULL DEFAULT NULL::character varying,
	favourites int8 NOT NULL DEFAULT '0'::bigint,
	CONSTRAINT characters_pk PRIMARY KEY (id)
);


-- anitools.mangaupdates definition

-- Drop table

-- DROP TABLE anitools.mangaupdates;

CREATE TABLE anitools.mangaupdates (
	id int8 NOT NULL,
	last_updated timestamp NULL,
	titles jsonb NULL,
	description text NULL,
	"type" anitools."mangaupdates_type" NULL,
	"year" varchar NULL,
	cover varchar NULL,
	genres jsonb NULL,
	categories jsonb NULL,
	latest_chapter int4 NULL,
	original_status text NULL,
	licensed bool NULL,
	scanlation_completed bool NULL,
	authors jsonb NULL,
	publishers jsonb NULL,
	publications jsonb NULL,
	CONSTRAINT mangaupdates_pk PRIMARY KEY (id)
);
CREATE INDEX mangaupdates_authors_idx ON anitools.mangaupdates USING gin (authors);
CREATE INDEX mangaupdates_categories_idx ON anitools.mangaupdates USING gin (categories);
CREATE INDEX mangaupdates_genres_idx ON anitools.mangaupdates USING gin (genres);
CREATE INDEX mangaupdates_publications_idx ON anitools.mangaupdates USING gin (publications);
CREATE INDEX mangaupdates_publishers_idx ON anitools.mangaupdates USING gin (publishers);
CREATE INDEX mangaupdates_titles_idx ON anitools.mangaupdates USING gin (titles);


-- anitools.mapping_votes definition

-- Drop table

-- DROP TABLE anitools.mapping_votes;

CREATE TABLE anitools.mapping_votes (
	media_id int8 NOT NULL,
	mangaupdates_id int8 NULL,
	voted_by int8 NOT NULL,
	is_multivote bool NOT NULL DEFAULT false
);


-- anitools.media definition

-- Drop table

-- DROP TABLE anitools.media;

CREATE TABLE anitools.media (
	id int8 NOT NULL,
	media_type anitools."media_media_type" NOT NULL,
	title_native varchar(250) NULL DEFAULT NULL::character varying,
	title_romaji varchar(500) NULL DEFAULT NULL::character varying,
	title_english varchar(250) NULL DEFAULT NULL::character varying,
	season anitools."media_season" NULL,
	season_year int2 NULL,
	format anitools."media_format" NULL,
	country_of_origin varchar(2) NOT NULL DEFAULT NULL::character varying,
	episodes int4 NULL,
	duration int4 NULL,
	"source" anitools."media_source" NULL,
	average_score int2 NULL,
	mean_score int2 NULL,
	favourites int8 NOT NULL DEFAULT '0'::bigint,
	popularity int8 NOT NULL,
	status anitools."media_status" NULL,
	is_adult int2 NOT NULL DEFAULT '0'::smallint,
	volumes int2 NULL,
	reviews int2 NOT NULL,
	start_date_y int2 NULL,
	start_date_m int2 NULL,
	start_date_d int2 NULL,
	end_date_y int2 NULL,
	end_date_m int2 NULL,
	end_date_d int2 NULL,
	cover_image varchar(250) NULL DEFAULT NULL::character varying,
	status_current int8 NOT NULL,
	status_planning int8 NOT NULL,
	status_completed int8 NOT NULL,
	status_dropped int8 NOT NULL,
	status_paused int8 NOT NULL,
	genres jsonb NULL,
	tags jsonb NULL,
	studios jsonb NULL,
	producers jsonb NULL,
	external_links jsonb NULL,
	total_duration int4 NULL GENERATED ALWAYS AS (episodes * duration) STORED,
	description text NULL,
	synonyms jsonb NULL,
	CONSTRAINT media_pk PRIMARY KEY (id)
);
CREATE INDEX media_countryoforigin_idx ON anitools.media USING btree (country_of_origin);
CREATE INDEX media_end_date_idx ON anitools.media USING btree (end_date_y, end_date_m, end_date_d);
CREATE INDEX media_episodes_idx ON anitools.media USING btree (episodes);
CREATE INDEX media_external_links_gin ON anitools.media USING gin (external_links);
CREATE INDEX media_format_idx ON anitools.media USING btree (format);
CREATE INDEX media_genres_gin ON anitools.media USING gin (genres);
CREATE INDEX media_isadult_idx ON anitools.media USING btree (is_adult);
CREATE INDEX media_media_type_idx ON anitools.media USING btree (media_type);
CREATE INDEX media_producers_gin ON anitools.media USING gin (producers);
CREATE INDEX media_season_idx ON anitools.media USING btree (season);
CREATE INDEX media_season_year_idx ON anitools.media USING btree (season_year);
CREATE INDEX media_source_idx ON anitools.media USING btree (source);
CREATE INDEX media_start_date_idx ON anitools.media USING btree (start_date_y, start_date_m, start_date_d);
CREATE INDEX media_start_date_y_idx ON anitools.media USING btree (start_date_y);
CREATE INDEX media_status_idx ON anitools.media USING btree (status);
CREATE INDEX media_studios_gin ON anitools.media USING gin (studios);
CREATE INDEX media_synonyms_idx ON anitools.media USING btree (synonyms);
CREATE INDEX media_tags_gin ON anitools.media USING gin (tags);
CREATE INDEX media_title_english_lowered_idx ON anitools.media USING btree (lower((title_english)::text));
CREATE INDEX media_title_native_idx ON anitools.media USING btree (title_native);
CREATE INDEX media_title_romaji_lowered_idx ON anitools.media USING btree (lower((title_romaji)::text));
CREATE INDEX media_total_duration_idx ON anitools.media USING btree (total_duration);


-- anitools.media_characters definition

-- Drop table

-- DROP TABLE anitools.media_characters;

CREATE TABLE anitools.media_characters (
	media_id int8 NOT NULL,
	character_id int8 NOT NULL,
	"role" anitools."media_characters_role" NOT NULL,
	voice_actor_id int8 NULL,
	voice_actor_lang varchar(50) NULL DEFAULT NULL::character varying
);
CREATE INDEX media_characters_character_id_fk ON anitools.media_characters USING btree (character_id);
CREATE INDEX media_characters_media_id_fk ON anitools.media_characters USING btree (media_id);
CREATE INDEX media_characters_role_idx ON anitools.media_characters USING btree (role);
CREATE INDEX media_characters_voice_actor_id_fk ON anitools.media_characters USING btree (voice_actor_id);
CREATE INDEX media_characters_voice_actor_lang_idx ON anitools.media_characters USING btree (voice_actor_lang);


-- anitools.media_relations definition

-- Drop table

-- DROP TABLE anitools.media_relations;

CREATE TABLE anitools.media_relations (
	media_id int8 NOT NULL,
	related_media_id int8 NOT NULL,
	relation_type anitools."media_relations_relation_type" NOT NULL
);
CREATE INDEX media_relations_media_id_fk ON anitools.media_relations USING btree (media_id);
CREATE INDEX media_relations_related_media_id_fk ON anitools.media_relations USING btree (related_media_id);
CREATE INDEX media_relations_relation_type_idx ON anitools.media_relations USING btree (relation_type);


-- anitools.media_staff definition

-- Drop table

-- DROP TABLE anitools.media_staff;

CREATE TABLE anitools.media_staff (
	media_id int8 NOT NULL,
	staff_id int8 NOT NULL,
	"role" varchar(300) NOT NULL,
	CONSTRAINT media_staff_pk PRIMARY KEY (media_id, staff_id, role)
);
CREATE INDEX media_staff_media_id_fk ON anitools.media_staff USING btree (media_id);
CREATE INDEX media_staff_role_idx ON anitools.media_staff USING btree (role);
CREATE INDEX media_staff_staff_id_fk ON anitools.media_staff USING btree (staff_id);


-- anitools.media_tagcollection definition

-- Drop table

-- DROP TABLE anitools.media_tagcollection;

CREATE TABLE anitools.media_tagcollection (
	category varchar NOT NULL,
	tag_name varchar NOT NULL,
	description varchar NULL,
	CONSTRAINT media_tagcollection_pk PRIMARY KEY (tag_name)
);
CREATE INDEX media_tagcollection_category_idx ON anitools.media_tagcollection USING btree (category);
CREATE UNIQUE INDEX media_tagcollection_tag_name_idx ON anitools.media_tagcollection USING btree (tag_name);


-- anitools.staff definition

-- Drop table

-- DROP TABLE anitools.staff;

CREATE TABLE anitools.staff (
	id int8 NOT NULL,
	name_first varchar(200) NULL DEFAULT NULL::character varying,
	name_middle varchar(200) NULL DEFAULT NULL::character varying,
	name_last varchar(200) NULL DEFAULT NULL::character varying,
	name_native varchar(200) NULL DEFAULT NULL::character varying,
	description text NULL,
	image varchar(200) NULL DEFAULT NULL::character varying,
	gender varchar(50) NULL DEFAULT NULL::character varying,
	blood_type varchar(50) NULL DEFAULT NULL::character varying,
	years_active_from int8 NULL,
	years_active_until int8 NULL,
	home_town varchar(100) NULL DEFAULT NULL::character varying,
	date_of_birth_y int2 NULL,
	date_of_birth_m int2 NULL,
	date_of_birth_d int2 NULL,
	date_of_death_y int2 NULL,
	date_of_death_m int2 NULL,
	date_of_death_d int2 NULL,
	favourites int8 NOT NULL DEFAULT '0'::bigint,
	CONSTRAINT staff_pk PRIMARY KEY (id)
);
CREATE INDEX staff_names_lowered_idx ON anitools.staff USING btree (lower((((((COALESCE(name_first, ''::character varying))::text || ' '::text) || (COALESCE(name_middle, ''::character varying))::text) || ' '::text) || (COALESCE(name_last, ''::character varying))::text)));


-- anitools."user" definition

-- Drop table

-- DROP TABLE anitools."user";

CREATE TABLE anitools."user" (
	id int8 NOT NULL,
	user_name varchar(128) NOT NULL,
	mapping_votes int4 NOT NULL DEFAULT 0,
	CONSTRAINT user_pk PRIMARY KEY (id)
);
CREATE UNIQUE INDEX user_name_lowered ON anitools."user" USING btree (lower((user_name)::text));


-- anitools.awc_community_lists definition

-- Drop table

-- DROP TABLE anitools.awc_community_lists;

CREATE TABLE anitools.awc_community_lists (
	media_id int8 NOT NULL,
	community_list varchar(100) NOT NULL,
	CONSTRAINT fk__awc_community_lists_media FOREIGN KEY (media_id) REFERENCES anitools.media(id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE INDEX awc_community_lists_community_list_idx ON anitools.awc_community_lists USING btree (community_list);
CREATE INDEX awc_community_lists_media_id_fk ON anitools.awc_community_lists USING btree (media_id);


-- anitools.awc_requirement_specific_lists definition

-- Drop table

-- DROP TABLE anitools.awc_requirement_specific_lists;

CREATE TABLE anitools.awc_requirement_specific_lists (
	media_id int8 NOT NULL,
	challenge_id int8 NOT NULL,
	requirement varchar(50) NOT NULL,
	CONSTRAINT fk_awc_requirement_specific_lists_awc_challenges FOREIGN KEY (challenge_id) REFERENCES anitools.awc_challenges(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
	CONSTRAINT fk_awc_requirement_specific_lists_media FOREIGN KEY (media_id) REFERENCES anitools.media(id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE INDEX awc_requirement_specific_lists_challenge_id_idx ON anitools.awc_requirement_specific_lists USING btree (challenge_id);
CREATE INDEX awc_requirement_specific_lists_media_id_fk ON anitools.awc_requirement_specific_lists USING btree (media_id);


-- anitools.media_external_ids definition

-- Drop table

-- DROP TABLE anitools.media_external_ids;

CREATE TABLE anitools.media_external_ids (
	media_id int8 NOT NULL,
	service anitools."media_external_ids_service" NOT NULL,
	external_id varchar(50) NOT NULL,
	"source" anitools."media_external_ids_sources" NOT NULL,
	CONSTRAINT media_external_ids_pk PRIMARY KEY (media_id, service, external_id, source),
	CONSTRAINT media_external_ids_fk FOREIGN KEY (media_id) REFERENCES anitools.media(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX media_external_ids_media_id_fk ON anitools.media_external_ids USING btree (media_id);
CREATE INDEX media_external_ids_service_idx ON anitools.media_external_ids USING btree (service);


-- anitools.media_external_links definition

-- Drop table

-- DROP TABLE anitools.media_external_links;

CREATE TABLE anitools.media_external_links (
	media_id int8 NOT NULL,
	site varchar(120) NOT NULL,
	CONSTRAINT media_external_links_pk PRIMARY KEY (media_id, site),
	CONSTRAINT fk__media FOREIGN KEY (media_id) REFERENCES anitools.media(id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE INDEX media_external_links_media_id_fk ON anitools.media_external_links USING btree (media_id);
CREATE INDEX media_external_links_site_idx ON anitools.media_external_links USING btree (site);


-- anitools.media_genres definition

-- Drop table

-- DROP TABLE anitools.media_genres;

CREATE TABLE anitools.media_genres (
	media_id int8 NOT NULL,
	genre anitools."media_genres_genre" NOT NULL,
	CONSTRAINT media_genres_pk PRIMARY KEY (media_id, genre),
	CONSTRAINT fk_media_genres_media FOREIGN KEY (media_id) REFERENCES anitools.media(id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE INDEX media_genres_genre_idx ON anitools.media_genres USING btree (genre);
CREATE INDEX media_genres_media_id_fk ON anitools.media_genres USING btree (media_id);


-- anitools.media_studios definition

-- Drop table

-- DROP TABLE anitools.media_studios;

CREATE TABLE anitools.media_studios (
	media_id int8 NOT NULL,
	studio varchar(50) NOT NULL,
	is_main int2 NOT NULL, -- Indicator wether the studio is listed as studio (1) or producer (0) on AniList
	CONSTRAINT media_studios_pk PRIMARY KEY (media_id, studio),
	CONSTRAINT fk_media_studios_media FOREIGN KEY (media_id) REFERENCES anitools.media(id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE INDEX media_studio_is_main_idx ON anitools.media_studios USING btree (is_main);
CREATE INDEX media_studio_media_id_fk ON anitools.media_studios USING btree (media_id);
CREATE INDEX media_studio_studio_idx ON anitools.media_studios USING btree (studio);
CREATE INDEX media_studios_studio_lowered_idx ON anitools.media_studios USING btree (lower((studio)::text));

-- Column comments

COMMENT ON COLUMN anitools.media_studios.is_main IS 'Indicator wether the studio is listed as studio (1) or producer (0) on AniList';


-- anitools.media_tags definition

-- Drop table

-- DROP TABLE anitools.media_tags;

CREATE TABLE anitools.media_tags (
	media_id int8 NOT NULL,
	tag varchar(50) NOT NULL DEFAULT ''::character varying,
	"rank" int2 NOT NULL DEFAULT '0'::smallint,
	is_spoiler int2 NOT NULL DEFAULT '0'::smallint,
	CONSTRAINT media_tags_pk PRIMARY KEY (media_id, tag),
	CONSTRAINT fk_media_tags_media FOREIGN KEY (media_id) REFERENCES anitools.media(id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE INDEX media_tags_media_id_fk ON anitools.media_tags USING btree (media_id);
CREATE INDEX media_tags_tag_idx ON anitools.media_tags USING btree (tag);


-- anitools.user_list_activities definition

-- Drop table

-- DROP TABLE anitools.user_list_activities;

CREATE TABLE anitools.user_list_activities (
	user_id int8 NOT NULL,
	media_id int8 NOT NULL,
	status anitools."user_list_activities_status" NOT NULL,
	created_at int8 NOT NULL,
	progress_from int8 NULL,
	progress_to int8 NULL,
	CONSTRAINT fk_user_list_activities_user FOREIGN KEY (user_id) REFERENCES anitools."user"(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX user_list_activities_created_at_idx ON anitools.user_list_activities USING btree (created_at);
CREATE INDEX user_list_activities_media_id_fk ON anitools.user_list_activities USING btree (media_id);
CREATE INDEX user_list_activities_status_idx ON anitools.user_list_activities USING btree (status);
CREATE INDEX user_list_activities_user_id_fk ON anitools.user_list_activities USING btree (user_id);


-- anitools.user_lists definition

-- Drop table

-- DROP TABLE anitools.user_lists;

CREATE TABLE anitools.user_lists (
	id bigserial NOT NULL,
	slug varchar(100) NOT NULL, -- Used as value for list dropdowns to make the list IDs irrelevant for the frontend
	user_id int8 NOT NULL,
	"name" varchar(256) NOT NULL,
	is_custom_list bool NOT NULL DEFAULT false,
	media_type anitools."user_lists_media_type" NOT NULL,
	"position" int8 NULL,
	CONSTRAINT user_lists_pk PRIMARY KEY (id),
	CONSTRAINT fk_user_lists_user FOREIGN KEY (user_id) REFERENCES anitools."user"(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX user_lists_is_custom_list_idx ON anitools.user_lists USING btree (is_custom_list);
CREATE INDEX user_lists_media_type_idx ON anitools.user_lists USING btree (media_type);
CREATE INDEX user_lists_name_idx ON anitools.user_lists USING btree (name);
CREATE INDEX user_lists_position_idx ON anitools.user_lists USING btree ("position");
CREATE UNIQUE INDEX user_lists_slug_idx ON anitools.user_lists USING btree (slug);
CREATE INDEX user_lists_user_id_fk ON anitools.user_lists USING btree (user_id);

-- Column comments

COMMENT ON COLUMN anitools.user_lists.slug IS 'Used as value for list dropdowns to make the list IDs irrelevant for the frontend';


-- anitools.user_media definition

-- Drop table

-- DROP TABLE anitools.user_media;

CREATE TABLE anitools.user_media (
	user_id int8 NOT NULL,
	media_id int8 NOT NULL,
	notes text NULL,
	status anitools."user_media_status" NULL,
	progress int8 NOT NULL DEFAULT '0'::bigint,
	progress_volumes int8 NOT NULL DEFAULT '0'::bigint,
	score numeric(5, 2) NULL DEFAULT NULL::numeric,
	repeat int2 NOT NULL DEFAULT '0'::smallint,
	started_at date NULL,
	completed_at date NULL,
	hidden_from_status_lists bool NOT NULL DEFAULT false,
	created_at int8 NOT NULL DEFAULT 0,
	updated_at int8 NOT NULL DEFAULT 0,
	CONSTRAINT fk_user_media_user FOREIGN KEY (user_id) REFERENCES anitools."user"(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX user_media_completet_at_idx ON anitools.user_media USING btree (completed_at);
CREATE INDEX user_media_media_id_fk ON anitools.user_media USING btree (media_id);
CREATE INDEX user_media_score_idx ON anitools.user_media USING btree (score);
CREATE INDEX user_media_started_at_idx ON anitools.user_media USING btree (started_at);
CREATE INDEX user_media_status_idx ON anitools.user_media USING btree (status);
CREATE INDEX user_media_user_id_fk ON anitools.user_media USING btree (user_id);


-- anitools.user_media_list definition

-- Drop table

-- DROP TABLE anitools.user_media_list;

CREATE TABLE anitools.user_media_list (
	user_id int8 NOT NULL,
	list_id int8 NOT NULL,
	media_id int8 NOT NULL,
	CONSTRAINT fk_user_media_list_user FOREIGN KEY (user_id) REFERENCES anitools."user"(id) ON DELETE CASCADE ON UPDATE CASCADE,
	CONSTRAINT fk_user_media_list_user_lists FOREIGN KEY (list_id) REFERENCES anitools.user_lists(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX user_media_list_list_id_fk ON anitools.user_media_list USING btree (list_id);
CREATE INDEX user_media_list_media_id_fk ON anitools.user_media_list USING btree (media_id);
CREATE INDEX user_media_list_user_id_fk ON anitools.user_media_list USING btree (user_id);