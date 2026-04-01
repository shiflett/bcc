--
-- PostgreSQL database dump
--

\restrict ffxQgpn2gRauWZE8xrus3K5FrrRaMRKFx9X9sz2N21JYETFdyEqeM8dCMKoDZRk

-- Dumped from database version 16.13
-- Dumped by pg_dump version 16.13

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

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: media; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media (
    id integer NOT NULL,
    trip_id integer NOT NULL,
    day_number integer,
    kind text DEFAULT 'photo'::text NOT NULL,
    body text,
    filename text,
    taken_at timestamp with time zone,
    lat double precision,
    lon double precision,
    placement_tier integer,
    display_order integer,
    width integer,
    height integer,
    created_at timestamp with time zone DEFAULT now(),
    CONSTRAINT media_photo_has_filename CHECK (((kind <> 'photo'::text) OR (filename IS NOT NULL)))
);


--
-- Name: media_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.media_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: media_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.media_id_seq OWNED BY public.media.id;


--
-- Name: trackpoints; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.trackpoints (
    id bigint NOT NULL,
    trip_id integer NOT NULL,
    lat double precision NOT NULL,
    lon double precision NOT NULL,
    ele double precision,
    recorded_at timestamp with time zone NOT NULL,
    source text DEFAULT 'gpx'::text NOT NULL
);


--
-- Name: trackpoints_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.trackpoints_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: trackpoints_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.trackpoints_id_seq OWNED BY public.trackpoints.id;


--
-- Name: trip_days; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.trip_days (
    id integer NOT NULL,
    trip_id integer NOT NULL,
    day_number integer NOT NULL,
    date date NOT NULL,
    notes text,
    point_count integer,
    gain_m double precision,
    loss_m double precision,
    distance_m double precision,
    started_at timestamp with time zone,
    ended_at timestamp with time zone
);


--
-- Name: trip_days_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.trip_days_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: trip_days_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.trip_days_id_seq OWNED BY public.trip_days.id;


--
-- Name: trip_senders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.trip_senders (
    id integer NOT NULL,
    trip_id integer NOT NULL,
    phone text NOT NULL,
    name text
);


--
-- Name: trip_senders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.trip_senders_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: trip_senders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.trip_senders_id_seq OWNED BY public.trip_senders.id;


--
-- Name: trips; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.trips (
    id integer NOT NULL,
    slug text NOT NULL,
    year integer NOT NULL,
    name text NOT NULL,
    subtitle text,
    description text,
    started_at timestamp with time zone,
    ended_at timestamp with time zone,
    is_live boolean DEFAULT false,
    cover_photo_id integer,
    created_at timestamp with time zone DEFAULT now(),
    token text,
    map_lat double precision,
    map_lon double precision,
    map_zoom integer DEFAULT 12,
    tracker_type text,
    tracker_id text,
    track_from timestamp with time zone,
    track_until timestamp with time zone
);


--
-- Name: trips_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.trips_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: trips_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.trips_id_seq OWNED BY public.trips.id;


--
-- Name: media id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media ALTER COLUMN id SET DEFAULT nextval('public.media_id_seq'::regclass);


--
-- Name: trackpoints id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trackpoints ALTER COLUMN id SET DEFAULT nextval('public.trackpoints_id_seq'::regclass);


--
-- Name: trip_days id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trip_days ALTER COLUMN id SET DEFAULT nextval('public.trip_days_id_seq'::regclass);


--
-- Name: trip_senders id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trip_senders ALTER COLUMN id SET DEFAULT nextval('public.trip_senders_id_seq'::regclass);


--
-- Name: trips id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trips ALTER COLUMN id SET DEFAULT nextval('public.trips_id_seq'::regclass);


--
-- Name: media media_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media
    ADD CONSTRAINT media_pkey PRIMARY KEY (id);


--
-- Name: trackpoints trackpoints_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trackpoints
    ADD CONSTRAINT trackpoints_pkey PRIMARY KEY (id);


--
-- Name: trip_days trip_days_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trip_days
    ADD CONSTRAINT trip_days_pkey PRIMARY KEY (id);


--
-- Name: trip_days trip_days_trip_id_day_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trip_days
    ADD CONSTRAINT trip_days_trip_id_day_number_key UNIQUE (trip_id, day_number);


--
-- Name: trip_senders trip_senders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trip_senders
    ADD CONSTRAINT trip_senders_pkey PRIMARY KEY (id);


--
-- Name: trip_senders trip_senders_trip_id_phone_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trip_senders
    ADD CONSTRAINT trip_senders_trip_id_phone_key UNIQUE (trip_id, phone);


--
-- Name: trips trips_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_pkey PRIMARY KEY (id);


--
-- Name: trips trips_token_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_token_key UNIQUE (token);


--
-- Name: trips trips_year_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_year_slug_key UNIQUE (year, slug);


--
-- Name: media_trip_day; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_trip_day ON public.media USING btree (trip_id, day_number, display_order);


--
-- Name: trackpoints_trip_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX trackpoints_trip_time ON public.trackpoints USING btree (trip_id, recorded_at);


--
-- Name: media media_trip_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media
    ADD CONSTRAINT media_trip_id_fkey FOREIGN KEY (trip_id) REFERENCES public.trips(id) ON DELETE CASCADE;


--
-- Name: trackpoints trackpoints_trip_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trackpoints
    ADD CONSTRAINT trackpoints_trip_id_fkey FOREIGN KEY (trip_id) REFERENCES public.trips(id) ON DELETE CASCADE;


--
-- Name: trip_days trip_days_trip_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trip_days
    ADD CONSTRAINT trip_days_trip_id_fkey FOREIGN KEY (trip_id) REFERENCES public.trips(id) ON DELETE CASCADE;


--
-- Name: trip_senders trip_senders_trip_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trip_senders
    ADD CONSTRAINT trip_senders_trip_id_fkey FOREIGN KEY (trip_id) REFERENCES public.trips(id) ON DELETE CASCADE;


--
-- Name: trips trips_cover_photo_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trips
    ADD CONSTRAINT trips_cover_photo_fk FOREIGN KEY (cover_photo_id) REFERENCES public.media(id) ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED;


--
-- PostgreSQL database dump complete
--

\unrestrict ffxQgpn2gRauWZE8xrus3K5FrrRaMRKFx9X9sz2N21JYETFdyEqeM8dCMKoDZRk

