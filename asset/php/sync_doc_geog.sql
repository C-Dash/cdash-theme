/* For each document item, set the following values using same from the Place Item 
   associated with the document
*/

/* Join Mapping_Markers with Item

select resource.resource_template_id, resource.title, mapping_marker.lat, mapping_marker.lng
from resource
INNER JOIN mapping_marker ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5;

select resource.resource_template_id, resource.title, docs.lat as orig_lat, docs.lng as orign_lng
from resource 
     INNER JOIN mapping_marker as docs 
ON resource.id=docs.item_id AND resource.resource_template_id = 5;

We first make a table 

select docs.resource_template_id, docs.title, docs.lat as orig_lat, docs.lng as orig_lng
from (select resource.resource_template_id, resource.title, docs.lat as orig_lat, docs.lng as orign_lng
      from resource 
      INNER JOIN mapping_marker 
      ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5; ) as docs

     INNER JOIN mapping_marker as docs 
     inner join mapping_marker as places
ON resource.id=docs.item_id AND resource.resource_template_id = 5 

select docs.id, docs.title, docs.lat as orig_lat, docs.lng as orig_lng
from (select resource.resource_template_id, resource.id, resource.title, mapping_marker.lat, mapping_marker.lng
      from resource 
      INNER JOIN mapping_marker 
      ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5) as docs


      select docs.id as doc_id, docs.title as doc_title, docs.lat as orig_lat, docs.lng as orig_lng
from (select resource.resource_template_id, resource.id, resource.title , mapping_marker.lat, mapping_marker.lng, 
      from resource 
          INNER JOIN mapping_marker 
          ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5) as docs
      (select resource.resource_template_id, resource.id, resource.title, mapping_marker.lat, mapping_marker.lng
       from resource 
         INNER JOIN mapping_marker
         ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 4) as places
where places.item_id = docs.item_id;



      select docs.id as doc_id, docs.title as doc_title, docs.lat as orig_lat, docs.lng as orig_lng
from (select resource.resource_template_id, resource.id , resource.title , mapping_marker.lat, mapping_marker.lng
      from resource INNER JOIN mapping_marker 
          ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5) as docs
      inner join
      (select resource.resource_template_id, resource.id, resource.title, mapping_marker.lat, mapping_marker.lng
       from resource INNER JOIN mapping_marker
             ON resource.id = mapping_marker.item_id AND resource.resource_template_id = 4 AND resource.id = mapping_marker.item_id) as places;

      select docs.id as doc_id, docs.title as doc_title, places.title as place_title, docs.lat as orig_lat, docs.lng as orig_lng, places.lat, places.lng
from (select resource.resource_template_id, resource.id , resource.title , mapping_marker.lat, mapping_marker.lng
      from resource INNER JOIN mapping_marker 
          ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5) as docs
      inner join
      (select resource.resource_template_id, resource.id, resource.title, mapping_marker.lat, mapping_marker.lng
       from resource INNER JOIN mapping_marker
             ON resource.id = mapping_marker.item_id AND resource.resource_template_id = 4 AND resource.id = mapping_marker.item_id) as places;


select docs.id as doc_id, doc_title, place_title, docs.lat as orig_lat, docs.lng as orig_lng, place_lat, place_lng, related_id
from  (select resource.resource_template_id, resource.id , resource.title as doc_title , mapping_marker.lat, mapping_marker.lng
      from resource 
           INNER JOIN mapping_marker ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5) as docs
           inner join (select value.resource_id, value.value_resource_id as related_id from mapping_marker inner join value on mapping_marker.id = value.value_resource_id) as related
           inner join (select resource.resource_template_id, resource.id as place_id, resource.title as place_title, mapping_marker.lat as place_lat, mapping_marker.lng as place_lng
                       from resource INNER JOIN mapping_marker ON resource.id = mapping_marker.item_id AND resource.resource_template_id = 4 AND resource.id = mapping_marker.item_id) as places;


select resource.resource_template_id, resource.id , resource.title as doc_title , mapping_marker.lat, mapping_marker.lng
      from resource 
           INNER JOIN mapping_marker ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5
           inner join (select value.resource_id, value.value_resource_id as related_id from mapping_marker inner join value on mapping_marker.id = value.value_resource_id) as related
           inner join (select resource.resource_template_id, resource.id as place_id, resource.title as place_title, mapping_marker.lat as place_lat, mapping_marker.lng as place_lng
                       from resource INNER JOIN mapping_marker ON resource.id = mapping_marker.item_id AND resource.resource_template_id = 4 AND resource.id = mapping_marker.item_id) as places;




Lets figure this out!  
Goal 1: Update DocMarkers   (selection of docs from markers)


/* THIS ALMOST WORKS!!!
select dm.item_id as doc_id, dm.lat as doc_lat, dm.lng as doc_lng, value.value_resource_id as place_id, mapping_marker.lat, mapping_marker.lng
from mapping_marker AS dm
    inner join value on resource_id = dm.item_id AND property_id = 290
    inner join mapping_marker ON dm.item_id = mapping_marker.item_id 
    inner join resource r on  r.id = dm.item_id AND r.resource_template_id = 5;


select dm.item_id as doc_id, dm.lat as doc_lat, dm.lng as doc_lng, v.value_resource_id as place_id, pm.lat, pm.lng
from mapping_marker dm, mapping_marker pm, value v, resource r
where dm.item_id = v.resource_id AND dm.item_id = r.id AND r.resource_template_id = 5 

 
/* THis Works.
UPDATE mapping_marker dm
inner join resource r on  r.id = dm.item_id
SET lat = 42.3662, lng = -71.07339
where r.resource_template_id = 5;

select * from mapping_marker dm
inner join resource on resource.id = dm.item_id AND resource_template_id = 5;




    Assign placecoords to docmarkers.coords  
        get placecoords from markers docPlace Doc_id   

    placecoords  


/* THIS IS THE SOLUTION */

UPDATE mapping_marker dm
inner join resource r on  r.id = dm.item_id
SET lat = 42.3662, lng = -71.07339
where r.resource_template_id = 5;

drop view if exists cdash_docgeo;
create view cdash_docgeo as 
select a.value_resource_id place_id, a.resource_id doc_id, lat place_lat, lng place_lng, b.value neighborhood, c.value chcDist
from value a
inner join mapping_marker on value_resource_id = item_id and property_id = (select id from property where local_name = 'placeItem')
inner join value b on b.resource_id = item_id and b.property_id = (select id from property where local_name = 'spatial')
inner join value c on c.resource_id = item_id and c.property_id = (select id from property where local_name = 'chcDist');

update mapping_marker
inner join cdash_docgeo on item_id = doc_id
set lat = place_lat, lng = place_lng;



select item_id, lat old_lat, lng old_lng, place_lat, place_lng 
from mapping_marker
inner join cdash_docgeo on item_id = doc_id;


Do-Over:  This query broke after I decided to quit using ItemType in favor of simply assigning doc items and place items to item sets.

CDASH_DocGeo is a view that has a row for each document item.  For each document item we get the ID of the linked place.  Then we get the coordinates for that place.  This view provides an easy lookup for the place coordinates for each document.   
The fields of CDASH Doc Geo are: 
Doc_id, Place_id, Place_lat, Place_lon



select a.resource_id doc_id, a.value_resource_id place_id, lat place_lat, lng place_lng 
from value a 
inner join mapping_marker on a.value_resource_id = item_id
and a.property_id = (select id from property where local_name = "placeItem);


*** October 16 2024 

These are the queries that have been workng:

1. Develop cdash_docgeo View:
   This view has one row per document, keyed on omeka item id for each document that includes 
   a foreign key for the parent place ID and then has the coordimnates for the place. 

drop view if exists cdash_docgeo;
create view dcdash_docgeo as
select resource_id doc_id, value_resource_id place_id, lat place_lat, lng place_lng 
from value 
inner join mapping_marker on value_resource_id = item_id
and property_id = (select id from property where local_name = 'placeItem');

2. Update markers table for documents

"update mapping_marker
inner join cdash_docgeo on item_id = doc_id
set lat = place_lat, lng = place_lng;";

*** New Stuff:  Lets create a cdash_places view, that will bring together the critical properties of places
    that we want to push onto the documents related to the place. 

drop view if exists cdash_places_view;
create view cdash_places_view as
SELECT item_id, resource.title, value.value as placetype, g.value as streetName, d.value as placeName, e.value as neighborhood, f.value as chcDist, c.value as streetAddress, trim(replace(c.value, g.value, "")) as houseNum, replace(CONCAT(g.value, "_", REPEAT("0",9 - REGEXP_INSTR( c.value,  '[^0-9]')),trim(replace(c.value, g.value, ""))),"-","_") as streetSort, lat, lng 
FROM mapping_marker
JOIN resource
  ON mapping_marker.item_id = resource.id
  AND resource_template_id = (select id from resource_template where label = 'CDASH Place')
JOIN value 
  on resource.id = resource_id
  AND property_id = (select id from property where local_name = 'placeType')
JOIN value d
  on resource.id = d.resource_id
  AND d.property_id = (select id from property where local_name = 'placeName')
JOIN value e
  on resource.id = e.resource_id
  AND e.property_id = (select id from property where local_name = 'Neighborhood')
JOIN value f
  on resource.id = f.resource_id
  AND f.property_id = (select id from property where local_name = 'chcDist')
JOIN value c
  on resource.id = c.resource_id
  AND c.property_id = (select id from property where local_name = 'streetAddress')
JOIN value g
  on resource.id = g.resource_id
  AND g.property_id = (select id from property where local_name = 'streetName');


### And a cdash_docs view (not to be confused with cdash_docgeo)

drop view if exists cdash_docs;
create view cdash_docs as
select value.resource_id as doc_id, concat(ps.title, " - ", d.value) as doctitle, concat(ps.streetsort,d.value) as docsort, value.value_resource_id as place_id, ps.placeName as placeName, ps.lat as place_lat, ps.lng as place_lng 
from value 
inner join mapping_marker on value.value_resource_id = item_id
and property_id = (select id from property where local_name = 'placeItem')
JOIN value d
  on value.resource_id = d.resource_id
  AND d.property_id = (select id from property where local_name = 'type')
JOIN cdash_places_view ps
  on value.value_resource_id = ps.item_id
  

### Audit queries

## Count eligible items that do not have markers

select id from resource WHERE id NOT IN (SELECT item_id FROM mapping_marker) and resource_template_id in (4,5)

## Count/list eligible items that do not have streetsort or some other property

select id from resource 
where resource_template_id in (4,5)
and id not in (Select resource_id from value where property_id = (select id from property where local_name = "streetSort"))

### Update Queries

update value where 
set value = (select streetSort from cdash_doc_view where doc_id = 
property_id = (select id from property where local_name = "streetSort"))
